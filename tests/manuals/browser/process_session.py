"""Linux-owned process-tree cleanup, including detached child sessions.

Only use while this runner creates no unrelated child processes concurrently.
A process-scoped subreaper adopts orphaned descendants; identities include kernel
start times, so a reused PID never grants authority to signal another process.
"""
from __future__ import annotations
import ctypes
import errno
import os
from pathlib import Path
import signal
import subprocess
import time


def snapshot():
    result={}
    for entry in Path('/proc').iterdir():
        if not entry.name.isdigit():continue
        try:
            raw=(entry/'stat').read_text();fields=raw[raw.rfind(')')+2:].split()
            result[int(entry.name)]={'pid':int(entry.name),'state':fields[0],
                'ppid':int(fields[1]),'group':int(fields[2]),'session':int(fields[3]),'start':int(fields[19])}
        except (OSError,ValueError,IndexError):continue
    return result


def enable_subreaper():
    libc=ctypes.CDLL(None,use_errno=True)
    # PR_SET_CHILD_SUBREAPER changes only this process's orphan-reaping role.
    if libc.prctl(36,1,0,0,0)!=0:raise OSError(ctypes.get_errno(),'Cannot enable owned-child reaping')
    value=ctypes.c_int()
    if libc.prctl(37,ctypes.byref(value),0,0,0)!=0 or value.value!=1:
        raise RuntimeError('Subreaper state could not be verified')


class ProcessSession:
    def __init__(self,args,**kwargs):
        if os.name!='posix' or not Path('/proc/self/stat').exists():raise RuntimeError('Linux process accounting required')
        if 'start_new_session' in kwargs:raise ValueError('Session ownership is managed internally')
        enable_subreaper()
        self.before={(p['pid'],p['start']) for p in snapshot().values() if p['ppid']==os.getpid()}
        self.process=subprocess.Popen(args,start_new_session=True,**kwargs)
        first=snapshot().get(self.process.pid)
        if first is None:raise RuntimeError('New process identity unavailable')
        self.root=(first['pid'],first['start']);self.owned={self.root};self.receipt=None
        self.signals=[]

    def discover(self):
        rows=snapshot();added=True
        # A child may deliberately start a new group/session. Ancestry and later
        # subreaper adoption retain ownership independently of that group change.
        while added:
            added=False
            live={pid for pid,start in self.owned if pid in rows and rows[pid]['start']==start}
            for row in rows.values():
                ident=(row['pid'],row['start'])
                adopted=(row['ppid']==os.getpid() and ident not in self.before and row['start']>=self.root[1])
                if ident not in self.owned and (row['ppid'] in live or adopted):
                    self.owned.add(ident);added=True
        return {pid:rows[pid] for pid,start in self.owned if pid in rows and rows[pid]['start']==start}

    def _signal(self,signum):
        owned=self.discover();all_rows=snapshot();groups=set()
        for row in owned.values():
            group=row['group']
            if group<=1 or group==os.getpgrp():continue
            members=[p for p in all_rows.values() if p['group']==group]
            if members and all((p['pid'],p['start']) in self.owned for p in members):groups.add(group)
        for group in groups:
            try:os.killpg(group,signum)
            except ProcessLookupError:pass
        # Cover owned children that changed groups between snapshots, without
        # signaling any unrelated member of the new group.
        for pid,row in self.discover().items():
            current=snapshot().get(pid)
            if current and current['start']==row['start']:
                try:os.kill(pid,signum)
                except ProcessLookupError:pass
        self.signals.append(signal.Signals(signum).name)

    def _reap(self):
        self.process.poll()
        for pid in self.discover():
            if pid==self.process.pid:continue
            try:os.waitpid(pid,os.WNOHANG)
            except ChildProcessError:pass
            except OSError as error:
                if error.errno!=errno.ECHILD:raise

    def cleanup(self,grace=2.0,kill_wait=3.0):
        if self.receipt is not None:return self.receipt
        started=time.monotonic();self.discover()
        for signum,wait in [(signal.SIGTERM,grace),(signal.SIGKILL,kill_wait)]:
            if not self.discover():break
            self._signal(signum);deadline=time.monotonic()+wait
            while time.monotonic()<deadline:
                self._reap()
                if not self.discover():break
                time.sleep(.025)
        self._reap();remaining=self.discover()
        if self.process.poll() is None and self.process.pid not in remaining:
            self.process.wait(timeout=.1)
        self.receipt={'new_session':True,'subreaper':True,'owned_processes':len(self.owned),
            'signals':self.signals,'remaining_processes':len(remaining),'all_reaped':not remaining,
            'elapsed_ms':round((time.monotonic()-started)*1000)}
        return self.receipt

    def communicate(self,timeout):return self.process.communicate(timeout=timeout)

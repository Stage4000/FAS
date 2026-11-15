"""Synthetic-only local fixtures. No PDF import or network."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.dont_write_bytecode = True
SCRIPT = Path(__file__).resolve().parents[2] / 'scripts/manuals-intake-inventory.py'
spec = importlib.util.spec_from_file_location('inventory', SCRIPT)
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
PDF = b'%PDF-1.7\nSynthetic inventory fixture.\n%%EOF\n'

class InventoryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup); self.root = Path(self.temp.name)
    def put(self, name, data=PDF):
        p = self.root/name; p.parent.mkdir(parents=True, exist_ok=True); p.write_bytes(data); return p
    def scan(self, **kw): return module.inventory(self.root, **kw)
    def test_nested_duplicate_case_and_exclusions(self):
        self.put('a.PDF'); self.put('nested/b.pdf'); self.put('other.pdf', PDF+b'other')
        self.put('clip.MP4', b'video'); self.put('note.txt', PDF); self.put('empty.pdf', b''); self.put('bad.pdf', b'not PDF')
        huge=self.put('huge.pdf')
        with huge.open('r+b') as f:f.truncate(module.MAX_BYTES+1)
        (self.root/'alias.pdf').symlink_to(self.root/'a.PDF'); (self.root/'nested-link').symlink_to(self.root/'nested')
        result=self.scan(); self.assertTrue(result['complete']); self.assertEqual(result['candidate_count'],3)
        self.assertEqual(result['candidate_bytes'],3*len(PDF)+5); self.assertEqual(result['unique_candidate_bytes'],2*len(PDF)+5)
        self.assertEqual(result['duplicate_groups'][0]['paths'],['a.PDF','nested/b.pdf'])
        self.assertEqual(result['excluded_counts'],{'video':1,'non_pdf':1,'empty':1,'bad_header':1,'oversized':1,'symlink':2})
        self.assertEqual(result,self.scan()); self.assertNotIn(str(self.root),json.dumps(result))
    def test_hardlinks_logical_bytes(self):
        self.put('a.pdf');os.link(self.root/'a.pdf',self.root/'b.pdf');r=self.scan()
        self.assertEqual(r['candidate_count'],2);self.assertEqual(r['candidate_bytes'],len(PDF)*2);self.assertEqual(r['unique_candidate_bytes'],len(PDF));self.assertEqual(r['duplicate_candidate_bytes'],len(PDF));self.assertEqual(r['regular_bytes_observed'],len(PDF)*2)
    def test_excluded_byte_summaries(self):
        self.put('x.MOV',b'123');self.put('x.txt',b'12345');self.put('x.pdf');r=self.scan()
        self.assertEqual(r['excluded_bytes'],{'video':3,'non_pdf':5});self.assertEqual(r['regular_bytes_observed'],8+len(PDF))
    def test_header_is_not_full_validation(self):
        self.put('fake.pdf',b'%PDF-2.0\nnot a valid PDF body'); self.assertEqual(self.scan()['candidate_count'],1)
    def test_root_symlink_rejected(self):
        (self.root/'link').symlink_to(self.root,target_is_directory=True); r=module.inventory(self.root/'link'); self.assertFalse(r['complete'])
    def test_depth_limit(self):
        self.put('a/b/c.pdf'); r=self.scan(max_depth=1); self.assertFalse(r['complete']); self.assertEqual(r['candidate_count'],0); self.assertIn('depth_limit',[e['reason'] for e in r['errors']])
    def test_entry_limit(self):
        for i in range(20):self.put(str(i)+'.pdf')
        r=self.scan(max_entries=3); self.assertFalse(r['complete']); self.assertLessEqual(r['entries_examined'],3); self.assertLessEqual(len(r['files']),3)
    def test_exact_entry_limit_complete(self):
        self.put('a.pdf'); self.assertTrue(self.scan(max_entries=1)['complete'])
    def test_fifo_excluded_without_open(self):
        os.mkfifo(self.root/'fifo.pdf'); self.assertEqual(self.scan()['excluded_counts'],{'non_regular':1})
    def test_symlink_ancestor_rejected(self):
        self.put('real/a.pdf'); (self.root/'alias').symlink_to(self.root/'real',target_is_directory=True)
        (self.root/'real/sub').mkdir()
        self.assertFalse(module.inventory(self.root/'alias/sub')['complete'])
    def test_nested_budget_reserved(self):
        for i in range(6):self.put('a/'+str(i)+'.pdf');self.put('z'+str(i)+'.pdf')
        r=self.scan(max_entries=7);self.assertLessEqual(r['entries_examined'],7);self.assertFalse(r['complete'])
    def test_stat_open_pdf_symlink_and_fifo_races(self):
        for kind in ['symlink','fifo']:
            with self.subTest(kind=kind):
                candidate=self.put('race.pdf'); native=os.open; raced=False
                outside=tempfile.TemporaryDirectory(); self.addCleanup(outside.cleanup)
                target=Path(outside.name)/'outside.pdf'; target.write_bytes(PDF)
                external_identity=(target.stat().st_dev,target.stat().st_ino); native_read=os.read; external_reads=[]
                def reading(fd,size):
                    info=os.fstat(fd)
                    if (info.st_dev,info.st_ino)==external_identity: external_reads.append(size)
                    return native_read(fd,size)
                def opening(name, flags, *args, **kw):
                    nonlocal raced
                    if name=='race.pdf' and not raced:
                        raced=True;candidate.unlink()
                        if kind=='symlink':candidate.symlink_to(target)
                        else:os.mkfifo(candidate)
                    return native(name,flags,*args,**kw)
                with patch.object(os,'open',opening),patch.object(os,'supports_dir_fd',os.supports_dir_fd|{opening}),patch.object(os,'read',reading):r=self.scan()
                self.assertEqual(external_reads,[])
                self.assertTrue(raced);self.assertFalse(r['complete']);self.assertEqual(r['candidate_count'],0)
                self.assertIn(r['files'][0]['status'],['changed','unreadable']);candidate.unlink()
    def test_stat_open_directory_symlink_race(self):
        directory=self.root/'nested';directory.mkdir();native=os.open;raced=False
        with tempfile.TemporaryDirectory() as outside:
            (Path(outside)/'secret.pdf').write_bytes(PDF)
            def opening(name,flags,*args,**kw):
                nonlocal raced
                if name=='nested' and not raced:
                    raced=True;directory.rmdir();directory.symlink_to(outside,target_is_directory=True)
                return native(name,flags,*args,**kw)
            with patch.object(os,'open',opening),patch.object(os,'supports_dir_fd',os.supports_dir_fd|{opening}):r=self.scan()
        self.assertTrue(raced);self.assertFalse(r['complete']);self.assertEqual(r['candidate_count'],0);self.assertNotIn('secret.pdf',json.dumps(r))
    def test_bad_limits(self):
        for kw in [{'max_entries':0},{'max_entries':100001},{'max_depth':65},{'max_depth':-1}]:
            with self.assertRaises(ValueError):self.scan(**kw)
    def test_unsupported_fails_closed(self):
        with patch.object(os,'supports_fd',set()):r=self.scan()
        self.assertFalse(r['complete']); self.assertEqual(r['errors'][0]['reason'],'unsupported_platform'); self.assertEqual(r['entries_examined'],0); self.assertEqual(r['excluded_counts'],{}); self.assertEqual(r['excluded_bytes'],{})
        with patch.object(os,'supports_follow_symlinks',set()):self.assertFalse(self.scan()['complete'])
    def mutated_read(self, mutate):
        p=self.put('a.pdf'); native=os.read; total=0; done=False
        def read(fd,size):
            nonlocal total,done
            if not done: done=True; mutate(p)
            b=native(fd,size); total+=len(b); return b
        with patch.object(os,'read',read):result=self.scan()
        return result,total
    def test_growth_bound(self):
        def grow(p):
            with p.open('r+b') as f:f.truncate(60*1024*1024)
        r,n=self.mutated_read(grow); self.assertFalse(r['complete']); self.assertEqual(r['candidate_count'],0); self.assertEqual(n,len(PDF)+1); self.assertEqual(r['files'][0]['status'],'changed')
    def test_truncation_excluded(self):
        r,n=self.mutated_read(lambda p:p.write_bytes(b'%PDF-1.7')); self.assertFalse(r['complete']); self.assertEqual(r['candidate_count'],0)
    def test_path_identity_swap_excluded(self):
        def swap(p):
            p.rename(p.with_suffix('.old')); p.write_bytes(PDF)
        r,n=self.mutated_read(swap); self.assertFalse(r['complete']); self.assertEqual(r['candidate_count'],0)
    def test_unreadable_file(self):
        # Patch read after safe open to simulate actual descriptor read denial.
        self.put('denied.pdf')
        with patch.object(os,'read',side_effect=PermissionError('private path must not leak')):r=self.scan()
        self.assertFalse(r['complete']); self.assertEqual(r['files'][0]['status'],'unreadable'); self.assertNotIn('private path',json.dumps(r))
    def test_filename_json_and_cli_status(self):
        self.put('quote"\n<script>.pdf')
        command=[sys.executable,str(SCRIPT),str(self.root)]
        p=subprocess.run(command,capture_output=True,text=True); self.assertEqual(p.returncode,0); self.assertEqual(json.loads(p.stdout)['files'][0]['path'],'quote"\n<script>.pdf'); self.assertEqual(p.stderr,'')
        p=subprocess.run(command+['--max-entries','1','--max-depth','0'],capture_output=True,text=True); self.assertEqual(p.returncode,0)
        self.put('nested/b.pdf');p=subprocess.run(command+['--max-depth','0'],capture_output=True,text=True); self.assertEqual(p.returncode,2); self.assertFalse(json.loads(p.stdout)['complete'])

    def test_read_budget_default_and_accounting(self):
        self.put('a.pdf'); self.put('bad.pdf', b'not pdf'); self.put('video.mp4', b'video')
        r=self.scan()
        self.assertTrue(r['complete']); self.assertEqual(r['limits']['max_read_bytes'],1024**3)
        self.assertEqual(r['bytes_read'],len(PDF)+7)
    def test_read_budget_skips_before_open(self):
        self.put('a.pdf'); real_open=os.open
        def checked(path,flags,*args,**kw):
            self.assertNotEqual(path,'a.pdf'); return real_open(path,flags,*args,**kw)
        with patch.object(module.os,'open',checked), patch.object(module.os,'supports_dir_fd',os.supports_dir_fd|{checked}): r=self.scan(max_read_bytes=len(PDF))
        self.assertFalse(r['complete']); self.assertEqual(r['bytes_read'],0)
        self.assertEqual(r['excluded_counts'],{'read_budget':1}); self.assertEqual(r['candidate_count'],0)
    def test_read_budget_exact_file_plus_probe(self):
        self.put('a.pdf');r=self.scan(max_read_bytes=len(PDF)+1)
        self.assertTrue(r['complete']); self.assertEqual(r['bytes_read'],len(PDF)); self.assertEqual(r['candidate_count'],1)
    def test_read_budget_global_across_directories(self):
        self.put('a.pdf');self.put('nested/b.pdf');self.put('nested/c.pdf')
        r=self.scan(max_read_bytes=2*len(PDF)+1)
        self.assertFalse(r['complete']);self.assertEqual(r['candidate_count'],2)
        self.assertEqual(r['bytes_read'],2*len(PDF));self.assertEqual(r['duplicate_candidate_bytes'],len(PDF))
        self.assertEqual(r['excluded_bytes']['read_budget'],len(PDF));self.assertEqual(r['regular_bytes_observed'],3*len(PDF))
    def test_read_budget_smaller_later_file_still_eligible(self):
        self.put('a.pdf',PDF*3);self.put('b.pdf');self.put('z.txt',b'hello')
        r=self.scan(max_read_bytes=len(PDF)+1)
        self.assertFalse(r['complete']);self.assertEqual(r['candidate_count'],1);self.assertEqual(r['files'][1]['status'],'candidate')
        self.assertEqual(r['excluded_counts'],{'read_budget':1,'non_pdf':1})
    def test_read_budget_growth_probe_counts(self):
        p=self.put('a.pdf');real_read=os.read;grew=False
        def grow(fd,n):
            nonlocal grew
            if not grew:
                grew=True
                with p.open('ab') as f:f.write(b'x')
            return real_read(fd,n)
        with patch.object(module.os,'read',side_effect=grow):r=self.scan(max_read_bytes=len(PDF)+1)
        self.assertFalse(r['complete']);self.assertEqual(r['bytes_read'],len(PDF)+1);self.assertEqual(r['candidate_count'],0)
        self.assertEqual(r['files'][0]['status'],'changed')
    def test_read_budget_failed_read_keeps_consumed_bytes(self):
        self.put('a.pdf');self.put('b.pdf');real_read=os.read;calls=0
        def fail_after_prefix(fd,n):
            nonlocal calls
            calls+=1
            if calls==1:return real_read(fd,8)
            if calls==2:raise OSError('synthetic read failure')
            return real_read(fd,n)
        with patch.object(module.os,'read',side_effect=fail_after_prefix):r=self.scan(max_read_bytes=len(PDF)+1)
        self.assertEqual(r['bytes_read'],8);self.assertEqual(r['candidate_count'],0)
        self.assertEqual(r['excluded_counts'],{'unreadable':1,'read_budget':1});self.assertFalse(r['complete'])
    def test_read_budget_invalid_arguments(self):
        for value in [0,-1,64*1024**3+1,True,1.5,'100']:
            with self.subTest(value=value),self.assertRaises(ValueError):self.scan(max_read_bytes=value)
    def test_read_budget_metadata_only_when_excluded(self):
        self.put('clip.mp4');self.put('note.txt');self.put('empty.pdf',b'')
        with patch.object(module.os,'read',side_effect=AssertionError('No content read expected')):r=self.scan(max_read_bytes=1)
        self.assertTrue(r['complete']);self.assertEqual(r['bytes_read'],0);self.assertEqual(r['candidate_count'],0)
    def test_read_budget_cli_partial_and_override(self):
        self.put('a.pdf');cmd=[sys.executable,str(SCRIPT),str(self.root),'--max-read-bytes']
        p=subprocess.run(cmd+[str(len(PDF))],capture_output=True,text=True)
        self.assertEqual(p.returncode,2);self.assertEqual(json.loads(p.stdout)['bytes_read'],0)
        p=subprocess.run(cmd+[str(len(PDF)+1)],capture_output=True,text=True)
        self.assertEqual(p.returncode,0);self.assertEqual(json.loads(p.stdout)['candidate_count'],1)
        p=subprocess.run(cmd+['0'],capture_output=True,text=True)
        self.assertEqual(p.returncode,2);self.assertIn('Read budget',p.stderr)

if __name__=='__main__':unittest.main()

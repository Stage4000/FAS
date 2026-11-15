#!/usr/bin/env python3
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import copy
import itertools

root=Path(__file__).resolve().parents[2]
script=root/'scripts/manuals-intake-capacity.py'
spec=importlib.util.spec_from_file_location('capacity',script);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
checks=0

def check(value):
    global checks
    assert value;checks+=1

def report(sizes):
    files=[{'path':f'mfg/manual-{i}.pdf','status':'candidate','bytes':n,'sha256':'a'*64} for i,n in enumerate(sizes)]
    return {'version':1,'complete':True,'errors':[],'files':files,'candidate_count':len(files),'candidate_bytes':sum(sizes)}

def reject(r,**kwargs):
    try:m.plan(r,**dict(capacity=10000,used=0,reserve=0,**kwargs))
    except ValueError:check(True)
    else:raise AssertionError('invalid report accepted')

r=report([10,30,20]);p=m.plan(r,90,10,5,batch_bytes=35)
check(p['additional_stored_bytes']==60)
check(p['final_used_bytes']==70)
check(p['peak_used_bytes']==80)
check(p['required_capacity_with_reserve']==85)
check(p['capacity_shortfall_bytes']==0 and p['candidate_plan_fits'])
check([x['transfer_bytes'] for x in p['batches']]==[30,30])
check(p['batches'][0]['files'][0]['bytes']==30)
check(r==report([10,30,20]))
check(m.plan(r,85,10,5)['candidate_plan_fits'])
check(m.plan(r,84,10,5)['capacity_shortfall_bytes']==1)
check(not m.plan(r,84,10,5)['candidate_plan_fits'])
check(m.plan(report([]),10,8,3)['capacity_shortfall_bytes']==1)
check(m.plan(report([]),11,8,3)['candidate_plan_fits'])
# All equal hashes still consume one original per path.
check(m.plan(report([10,10,10]),100,0,0)['additional_stored_bytes']==30)
check(m.plan(report([10,10,10]),100,0,0)['peak_used_bytes']==40)
p=m.plan(r,1000,0,0,upload_limit=20,batch_bytes=25)
check(len(p['blocked_files'])==1)
check(p['blocked_files'][0]['reasons']==['effective_upload_limit','batch_transfer_limit'])
check(not p['candidate_plan_fits'])
for complete in [False]:
 x=report([10]);x['complete']=complete;x['errors']=[{'reason':'read_budget','path':'skipped.pdf'}]
 p=m.plan(x,1000,0,0);check(not p['candidate_plan_fits'] and p['additional_stored_bytes']==10)
x=report([10]);x['files'].append({'path':'video.mp4','status':'video','bytes':99999});check(m.plan(x,100,0,0)['excluded_record_count']==1)
for key,val in [('version',True),('version',2),('complete',1),('errors',None),('files',{}),('candidate_count',True),('candidate_count',0),('candidate_bytes',9)]:
 x=report([10]);x[key]=val;reject(x)
x=report([10]);x['errors']=[{'reason':'unreadable'}];reject(x)
for key,val in [('path','/tmp/a'),('path','../a'),('path','mfg//a'),('path','.'),('path','a\x00b'),('path','a'*4097),('status',None),('status','typo'),('status','read_budget'),('status','unreadable'),('status','changed'),('bytes',True),('bytes',0),('bytes',m.MAX_PDF+1),('bytes',1.5),('sha256','g'*64),('sha256','a'*63)]:
 x=report([10]);x['files'][0][key]=val;reject(x)
x=report([10,20]);x['files'][1]['path']=x['files'][0]['path'];reject(x)
for kwargs in [dict(upload_limit=0),dict(upload_limit=m.MAX_PDF+1),dict(batch_bytes=0)]:reject(report([10]),**kwargs)
for args in [(-1,0,0),(True,0,0),(1,-1,0),(1,0,-1),(m.MAX_VALUE+1,0,0)]:
 try:m.plan(report([10]),*args)
 except ValueError:check(True)
 else:raise AssertionError('bad limit accepted')
# Exhaustively compare advisory descending order against every sequential order.
for sizes in itertools.product(range(1,5),repeat=4):
 expected=min(max(sum(order[:i])+2*n for i,n in enumerate(order)) for order in set(itertools.permutations(sizes)))
 check(m.plan(report(sizes),1000,0,0)['peak_used_bytes']==expected)

def cli(data,*args):
 return subprocess.run([sys.executable,str(script),'--capacity-bytes','100','--used-bytes','0','--reserve-bytes','0',*args],input=data,capture_output=True)
a=cli(json.dumps(report([10])).encode());check(a.returncode==0 and json.loads(a.stdout)['candidate_plan_fits'])
a=cli(json.dumps(report([50,50])).encode());check(a.returncode==2 and json.loads(a.stdout)['capacity_shortfall_bytes']==50)
for raw in [b'{"private-secret":',b'{"version":1,"version":2}',b'\xff',b'['*2000+b']'*2000,b' '* (m.MAX_INPUT+1)]:
 a=cli(raw);check(a.returncode==2 and json.loads(a.stdout)['error']=='invalid_inventory_or_limits' and b'private-secret' not in a.stdout)
print(f'{checks} capacity-planning checks passed')

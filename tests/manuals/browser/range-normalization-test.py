"""Closed expectations for controlled same-request duplicate-header observations."""
import sys
sys.dont_write_bytecode = True
from pathlib import Path
import json
import tempfile
from range_normalization import PAIRS,expected_duplicate,read_duplicate_observation

checks=0

def check(condition,label):
    global checks
    assert condition,label;checks+=1;print('PASS '+label)


def rejects(fn,label):
    try:fn()
    except ValueError:check(True,label)
    else:raise AssertionError(label)

source=bytes(range(100))
for case,(first,last) in PAIRS.items():
    for which,received in [('first',first),('last',last)]:
        plan=expected_duplicate(case,received,len(source))
        start,end=(0,1) if received=='bytes=0-1' else (4,5)
        check(plan=={'kind':'single-'+which,'status':206,'start':start,'end':end,'length':2,'content_range':f'bytes {start}-{end}/100'},case+' '+which+' requires exact206 slice metadata')
        check(source[plan['start']:plan['end']+1]==bytes([start,end]),case+' '+which+' selects only the observed bytes')
    for separator in [',',', ']:
        check(expected_duplicate(case,first+separator+last,len(source))=={'kind':'combined','status':416,'start':None,'end':None,'length':0,'content_range':'bytes */100'},case+' combined form remains empty416')
    for invalid in [None,'','bytes=6-7','bytes=0-1,4-5','bytes=0-1\n',last+', '+first]:
        rejects(lambda value=invalid:expected_duplicate(case,value,len(source)),case+' rejects missing/rewritten/unexpected normalization')
rejects(lambda:expected_duplicate('unknown','bytes=0-1',100),'Unknown input case rejected')
rejects(lambda:expected_duplicate('forward','bytes=0-1',5),'Undersized synthetic document rejected')
with tempfile.TemporaryDirectory(prefix='fas-range-observation-') as tmp:
    marker=Path(tmp)/'observation.json'
    check(read_duplicate_observation('forward',marker,100)==({},None),'Missing same-request observation fails closed without losing response diagnostics')
    for label,raw in [('malformed','{broken'),('wrong type','[]'),('stale case',json.dumps({'case':'reverse','range':'bytes=0-1'}))]:
        marker.write_text(raw)
        check(read_duplicate_observation('forward',marker,100)==({},None),label+' observation fails closed without raising before the safe receipt')
    marker.write_text(json.dumps({'case':'forward','range':None}))
    check(read_duplicate_observation('forward',marker,100)[1] is None,'Missing normalized value cannot authorize a fallback')
    marker.write_text(json.dumps({'case':'forward','range':'bytes=4-5'}))
    check(read_duplicate_observation('forward',marker,100)[1]==expected_duplicate('forward','bytes=4-5',100),'Matching same-request observation preserves exact single-range plan')
print(f'{checks} normalization-oracle checks passed. No server or browser launched.')

#!/usr/bin/env python3
"""Read-only arithmetic planning from a bounded inventory JSON, never an importer."""
import argparse
import json
import re
import sys

MAX_INPUT = 32 * 1024 * 1024
MAX_RECORDS = 100000
MAX_VALUE = 2**53 - 1
MAX_PDF = 50 * 1024 * 1024


def integer(value, name, minimum=0, maximum=MAX_VALUE):
    if type(value) is not int or not minimum <= value <= maximum:
        raise ValueError('Invalid ' + name)
    return value


def plan(report, capacity, used, reserve, upload_limit=MAX_PDF, batch_bytes=256*1024*1024):
    for value, name in [(capacity,'capacity'),(used,'used'),(reserve,'reserve')]:
        integer(value,name)
    integer(upload_limit,'upload_limit',1,MAX_PDF)
    integer(batch_bytes,'batch_bytes',1)
    if not isinstance(report,dict) or type(report.get('version')) is not int or report.get('version') != 1 or type(report.get('complete')) is not bool:
        raise ValueError('Invalid inventory envelope')
    errors=report.get('errors')
    if not isinstance(errors,list) or len(errors)>MAX_RECORDS or (report['complete'] and errors):
        raise ValueError('Inconsistent inventory completeness')
    records=report.get('files')
    if not isinstance(records,list) or len(records)>MAX_RECORDS:
        raise ValueError('Invalid inventory records')
    seen=set(); candidates=[]; excluded=0; excluded_counts={}
    for record in records:
        if not isinstance(record,dict): raise ValueError('Invalid inventory record')
        path=record.get('path'); status=record.get('status')
        if not isinstance(path,str) or not path or len(path)>4096 or path.startswith('/') or any(x in ('','.', '..') for x in path.split('/')) or '\x00' in path:
            raise ValueError('Invalid relative path label')
        if path in seen: raise ValueError('Duplicate path label')
        seen.add(path)
        if status not in ('candidate','video','non_pdf','empty','oversized','read_budget','changed','bad_header','unreadable','symlink','non_regular'):
            raise ValueError('Invalid record status')
        if report['complete'] and status in ('read_budget','changed','unreadable'):
            raise ValueError('Inconsistent inventory completeness')
        if status!='candidate':
            excluded+=1;excluded_counts[status]=excluded_counts.get(status,0)+1;continue
        size=integer(record.get('bytes'),'candidate bytes',1,MAX_PDF)
        digest=record.get('sha256')
        if not isinstance(digest,str) or re.fullmatch('[0-9a-f]{64}',digest) is None:
            raise ValueError('Invalid candidate digest')
        candidates.append({'path':path,'bytes':size})
    total=sum(x['bytes'] for x in candidates)
    if integer(report.get('candidate_count'),'candidate_count')!=len(candidates) or integer(report.get('candidate_bytes'),'candidate_bytes')!=total:
        raise ValueError('Inconsistent candidate totals')
    # The real importer currently stores each path separately, even exact duplicates.
    # Largest-first reduces temporary-copy peaks for sequential processing; it is
    # an advisory order only, not a concurrent upload scheduler or authorization.
    candidates.sort(key=lambda x:(-x['bytes'],x['path']))
    stored=0; peak=used; batches=[]; batch=[]; batch_total=0; blocked=[]
    for item in candidates:
        size=item['bytes'];peak=max(peak,used+stored+2*size);stored+=size
        reasons=[]
        if size>upload_limit: reasons.append('effective_upload_limit')
        if size>batch_bytes: reasons.append('batch_transfer_limit')
        if reasons: blocked.append({**item,'reasons':reasons})
        if batch and batch_total+size>batch_bytes:
            batches.append({'files':batch,'transfer_bytes':batch_total});batch=[];batch_total=0
        batch.append(item);batch_total+=size
        if size>batch_bytes:
            batches.append({'files':batch,'transfer_bytes':batch_total});batch=[];batch_total=0
    if batch:batches.append({'files':batch,'transfer_bytes':batch_total})
    required=peak+reserve
    complete=report['complete']
    return {'version':1,'estimates_only':True,'inventory_complete':complete,
            'capacity_bytes':capacity,'used_bytes':used,'reserve_bytes':reserve,
            'effective_upload_limit':upload_limit,'batch_transfer_limit':batch_bytes,
            'candidate_count':len(candidates),'excluded_record_count':excluded,'excluded_counts':excluded_counts,
            'additional_stored_bytes':total,'final_used_bytes':used+total,
            'peak_used_bytes':peak,'required_capacity_with_reserve':required,
            'capacity_shortfall_bytes':max(0,required-capacity),
            'candidate_plan_fits':complete and not blocked and required<=capacity,
            'blocked_files':blocked,'batches':batches,
            'limitations':['Arithmetic estimate only; inventory and capacity inputs are not authenticated or refreshed.',
                'One stored copy per candidate; duplicate hashes do not reduce storage.',
                'Serial processing assumes one extra full temporary upload copy on the same capacity budget; batches are review groups, not parallel uploads.',
                'Reserve must cover database, filesystem allocation, logs, other site growth, retries and concurrent work; no automatic reserve is inferred.',
                'Excluded and unobserved files are not planned. Incomplete inventory cannot yield a fitting whole-library result.',
                'No files opened by record paths, fetched, imported, compressed, deleted or published. Rights, valid PDF and production acceptance remain required.']}


def unique_object(pairs):
    result={}
    for key,value in pairs:
        if key in result: raise ValueError('Duplicate JSON key')
        result[key]=value
    return result


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--capacity-bytes',type=int,required=True)
    parser.add_argument('--used-bytes',type=int,required=True)
    parser.add_argument('--reserve-bytes',type=int,required=True)
    parser.add_argument('--upload-limit-bytes',type=int,default=MAX_PDF)
    parser.add_argument('--batch-bytes',type=int,default=256*1024*1024)
    args=parser.parse_args()
    try:
        raw=sys.stdin.buffer.read(MAX_INPUT+1)
        if len(raw)>MAX_INPUT: raise ValueError('Inventory input exceeds 32 MiB')
        result=plan(json.loads(raw,object_pairs_hook=unique_object),args.capacity_bytes,args.used_bytes,args.reserve_bytes,args.upload_limit_bytes,args.batch_bytes)
    except (ValueError,TypeError,RecursionError,UnicodeError) as error:
        # Do not echo untrusted source text or filenames in parse errors.
        print(json.dumps({'version':1,'error':'invalid_inventory_or_limits','estimates_only':True}));return 2
    print(json.dumps(result,ensure_ascii=True,sort_keys=True))
    return 0 if result['candidate_plan_fits'] else 2


if __name__=='__main__':
    sys.exit(main())

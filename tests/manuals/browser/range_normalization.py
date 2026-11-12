"""Independent expectations for the two controlled duplicate-Range probes."""
import json

PAIRS={'forward':('bytes=0-1','bytes=4-5'),'reverse':('bytes=4-5','bytes=0-1')}


def expected_duplicate(case,received,size):
    """Accept only observed first/last retention or ordered comma combination.

    Missing, reordered, rewritten or otherwise unexpected values fail. This is
    a test oracle, not an alternate runtime parser or a raw-header security gate.
    """
    if case not in PAIRS or not isinstance(size,int) or isinstance(size,bool) or size<6:
        raise ValueError('Invalid controlled normalization probe')
    first,last=PAIRS[case]
    if received in (first+','+last,first+', '+last):
        return {'kind':'combined','status':416,'start':None,'end':None,'length':0,'content_range':f'bytes */{size}'}
    if received==first:kind='single-first'
    elif received==last:kind='single-last'
    else:raise ValueError('Missing or unexpected Range normalization')
    start,end=(0,1) if received=='bytes=0-1' else (4,5)
    return {'kind':kind,'status':206,'start':start,'end':end,'length':2,'content_range':f'bytes {start}-{end}/{size}'}


def read_duplicate_observation(case,path,size):
    """Fail closed but let the caller retain status/length when PHP never ran."""
    try:
        observation=json.loads(path.read_text())
    except (OSError,ValueError):
        return {},None
    if not isinstance(observation,dict):return {},None
    if observation.get('case')!=case:return {},None
    try:plan=expected_duplicate(case,observation.get('range'),size)
    except ValueError:plan=None
    return observation,plan

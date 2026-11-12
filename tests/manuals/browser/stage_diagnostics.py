"""Bounded structured stage evidence. Never retains raw stdout/stderr or messages."""
from pathlib import Path
import json
import re

MAX_INPUT_BYTES=65536
MAX_EVENTS=16
MAX_FRAMES=12
EXCEPTIONS=('ModuleNotFoundError','ImportError','AssertionError','RuntimeError','PermissionError',
            'FileNotFoundError','TimeoutError','TimeoutExpired','CalledProcessError','ValueError',
            'TypeError','OSError','InvalidArgumentException','RuntimeException','PDOException','Error')
EXTENSIONS=('pdo_sqlite','sqlite3','fileinfo','mbstring','dom','json','session')
ERRORS=('reportlab_missing','php_probe_failed','php_extension_missing','sqlite_json_missing')


def tail(value):
    raw=value if isinstance(value,bytes) else str(value or '').encode('utf-8',errors='replace')
    return raw[-MAX_INPUT_BYTES:].decode('utf-8',errors='replace'),len(raw),len(raw)>MAX_INPUT_BYTES


def runtime_receipt(value):
    """Accept fixed enums, numeric versions and booleans only, never arbitrary text."""
    if not isinstance(value,dict):return None
    version=lambda x:x if isinstance(x,str) and re.fullmatch(r'\d+(?:\.\d+){1,3}(?:[a-z]+\d*)?',x) else None
    result={
        'python_executable':value.get('python_executable') if value.get('python_executable') in ('/usr/bin/python3','other') else 'other',
        'python_version':version(value.get('python_version')),
        'reportlab_available':value.get('reportlab_available') is True,
        'reportlab_version':version(value.get('reportlab_version')),
        'reportlab_distribution_package':value.get('reportlab_distribution_package') is True,
        'php_executable':value.get('php_executable') if value.get('php_executable') in ('/usr/bin/php8.3','other') else 'other',
        'php_version':version(value.get('php_version')),
        'extensions':{name:value.get('extensions',{}).get(name) is True for name in EXTENSIONS} if isinstance(value.get('extensions',{}),dict) else {},
        'sqlite_json':value.get('sqlite_json') is True,
        'errors':[name for name in ERRORS if name in value.get('errors',[])] if isinstance(value.get('errors',[]),list) else [],
    }
    return result


def normalization_receipt(value):
    """Only controlled cases, fixed enums and exact-response checks are retained."""
    if not isinstance(value,dict) or value.get('case') not in ('forward','reverse'):return None
    def integer(x,low,high):return x if isinstance(x,int) and not isinstance(x,bool) and low<=x<=high else None
    version=value.get('php_version')
    result={'php_version':version if isinstance(version,str) and re.fullmatch(r'\d+\.\d+\.\d+',version) else None,
            'sapi':value.get('sapi') if value.get('sapi') in ('cli-server','apache2handler','fpm-fcgi') else 'other',
            'case':value['case'],
            'kind':value.get('kind') if value.get('kind') in ('combined','single-first','single-last','unrecognized') else 'unrecognized',
            'observed_status':integer(value.get('observed_status'),100,599),
            'expected_status':value.get('expected_status') if value.get('expected_status') in (206,416) else None,
            'response_bytes':integer(value.get('response_bytes'),0,52428800)}
    for key in ('status_matches','length_matches','range_matches','body_matches','safety_headers_match'):
        result[key]=value.get(key) is True
    return result


def release_config_receipt(value):
    """No config values, paths, hashes, response bodies or headers are retained."""
    if not isinstance(value,dict) or value.get('case')!='string_release' or value.get('route') not in ('catalog','viewer','file'):return None
    def integer(x,low,high):return x if isinstance(x,int) and not isinstance(x,bool) and low<=x<=high else None
    version=value.get('php_version')
    result={'case':'string_release','route':value['route'],
            'php_version':version if isinstance(version,str) and re.fullmatch(r'\d+\.\d+\.\d+',version) else None,
            'sapi':value.get('sapi') if value.get('sapi') in ('cli-server','apache2handler','fpm-fcgi') else 'other',
            'loaded_flag':value.get('loaded_flag') if value.get('loaded_flag') in ('boolean_true','boolean_false','string_true','other') else 'unobserved',
            'http_status':integer(value.get('http_status'),100,599),
            'response_bytes':integer(value.get('response_bytes'),0,52428800),
            'body_kind':value.get('body_kind') if value.get('body_kind') in ('pdf','catalog_not_found','manual_not_found','other') else 'other'}
    for key in ('observation_present','python_disk_matches_expected','php_disk_matches_expected','opcache_loaded','php_diagnostic_body'):
        result[key]=value.get(key) is True
    for key in ('opcache_enable','opcache_enable_cli','opcache_validate_timestamps','opcache_revalidate_path'):
        result[key]=value.get(key) if isinstance(value.get(key),bool) else None
    for key in ('opcache_revalidate_freq','opcache_file_update_protection'):
        result[key]=integer(value.get(key),0,86400)
    return result


def config_reload_receipt(value):
    cases=('string_release','string_restore','boolean_release','boolean_restore','storage_outside','storage_outside_link','storage_direct_link','storage_alias','storage_restore')
    if not isinstance(value,dict) or value.get('case') not in cases or value.get('route') not in ('catalog','viewer','file','private_preview','private_file','reload'):return None
    status=value.get('http_status')
    result={'case':value['case'],'route':value['route'],'method':value.get('method') if value.get('method') in ('no_opcache','forced_invalidation') else 'unavailable',
            'http_status':status if type(status) is int and 100<=status<=599 else None}
    for key in ('disk_matches','flag_matches','storage_matches','same_server_process'):result[key]=value.get(key) is True
    return result


def summarize(stdout,stderr,source_paths,*,exit_code=None,timed_out=False):
    known=sorted(set(source_paths),key=len,reverse=True)
    out,out_bytes,out_cut=tail(stdout);err,err_bytes,err_cut=tail(stderr)
    text=out+'\n'+err
    events=[];frames=[];receipt=None;normalization=[];release_config=[];reload_summary=None
    def event(name):
        if name not in events and len(events)<MAX_EVENTS:events.append(name)
    if re.search(r"No module named ['\"]reportlab(?:\.[A-Za-z_][A-Za-z0-9_]*)*['\"]",text):event('reportlab_module_missing')
    if 'could not find driver' in text:event('pdo_driver_missing')
    if re.search(r'Class [\'\"]finfo[\'\"] not found',text):event('fileinfo_extension_missing')
    if re.search(r'undefined function mb_[a-z_]+\(',text,re.I):event('mbstring_extension_missing')
    if 'open_basedir restriction in effect' in text:event('open_basedir_warning')
    if re.search(r'(?:PHP )?(?:Fatal error|Warning|Parse error|Notice|Deprecated):',text):event('php_diagnostic')
    for name in EXCEPTIONS:
        if re.search(r'(?<![A-Za-z])'+name+r'(?=[:\s])',text):event(name)
    for line in text.splitlines():
        # Trace locations are drawn only from the pinned repository path inventory.
        match=re.search(r'File "([^"\n]+)", line ([0-9]{1,8})(?![0-9])',line)
        if not match:match=re.search(r'\bin ([^\n]+?)(?::| on line )([0-9]{1,8})(?![0-9])(?:\D|$)',line)
        if not match:match=re.search(r'^#[0-9]+\s+([^\n(]+)\(([0-9]{1,8})\):',line)
        if match:
            raw_path,number=match.groups()
            for rel in known:
                if raw_path==rel or raw_path.endswith('/'+rel):
                    frame={'source':rel,'line':int(number)}
                    if frame not in frames and len(frames)<MAX_FRAMES:frames.append(frame)
                    break
        if line.startswith('FAS_RUNTIME_RECEIPT '):
            try:receipt=runtime_receipt(json.loads(line[len('FAS_RUNTIME_RECEIPT '):]))
            except (ValueError,TypeError):event('malformed_runtime_receipt')
        if line.startswith('FAS_RANGE_NORMALIZATION_RECEIPT '):
            try:
                note=normalization_receipt(json.loads(line[len('FAS_RANGE_NORMALIZATION_RECEIPT '):]))
                if note is None:event('malformed_normalization_receipt')
                elif len(normalization)<4:normalization.append(note)
            except (ValueError,TypeError):event('malformed_normalization_receipt')
        if line.startswith('FAS_RELEASE_CONFIG_RECEIPT '):
            try:
                note=release_config_receipt(json.loads(line[len('FAS_RELEASE_CONFIG_RECEIPT '):]))
                if note is None:event('malformed_release_config_receipt')
                elif len(release_config)<3:release_config.append(note)
            except (ValueError,TypeError):event('malformed_release_config_receipt')
        if line.startswith('FAS_CONFIG_RELOAD_RECEIPT '):
            try:
                note=config_reload_receipt(json.loads(line[len('FAS_CONFIG_RELOAD_RECEIPT '):]))
                if note is None:event('malformed_config_reload_receipt')
                else:
                    if reload_summary is None:reload_summary={'observations':0,'all_verified':True,'cases':{},'methods':[]}
                    verified=note['method']!='unavailable' and all(note[k] for k in ('disk_matches','flag_matches','storage_matches','same_server_process'))
                    reload_summary['observations']=min(64,reload_summary['observations']+1)
                    reload_summary['all_verified'] &= verified
                    item=reload_summary['cases'].setdefault(note['case'],{'requests':0,'verified':True})
                    item['requests']=min(64,item['requests']+1);item['verified'] &= verified
                    if note['method'] not in reload_summary['methods']:reload_summary['methods'].append(note['method'])
                    if not verified and 'first_failure' not in reload_summary:reload_summary['first_failure']=note
            except (ValueError,TypeError):event('malformed_config_reload_receipt')
    result={'exit_code':exit_code if isinstance(exit_code,int) else None,'timed_out':bool(timed_out),
            'stdout_bytes':out_bytes,'stderr_bytes':err_bytes,'input_tail_truncated':out_cut or err_cut,
            'events':events,'source_frames':frames,
            'pass_lines_in_retained_tail':sum(1 for line in text.splitlines() if line.startswith('PASS '))}
    if receipt is not None:result['runtime']=receipt
    if normalization:result['range_normalization']=normalization
    if release_config:result['release_config']=release_config
    if reload_summary is not None:result['fixture_config_reload']=reload_summary
    # All content above is fixed labels, known source paths, counts or sanitized versions.
    assert len(json.dumps(result).encode())<=8192,'Structured diagnostics exceeded their fixed budget'
    return result

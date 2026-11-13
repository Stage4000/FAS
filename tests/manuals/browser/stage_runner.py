"""Execute one owned subprocess and retain only structured sanitized diagnostics."""
import subprocess
from process_session import ProcessSession
from stage_diagnostics import summarize


def cleanup_job(label,job):
    try:return {'stage':label,**job.cleanup()}
    except Exception as error:return {'stage':label,'all_reaped':False,'error':type(error).__name__}


def execute_stage(label,command,env,timeout,root,report,jobs,source_paths):
    item={'stage':label,'status':'running'};report['stages'].append(item)
    stdout='';stderr='';timed_out=False
    job=ProcessSession(command,cwd=root,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
                       text=True,encoding='utf-8',errors='replace')
    jobs.append((label,job))
    try:
        stdout,stderr=job.communicate(timeout=timeout)
        if job.process.returncode!=0:raise RuntimeError('Isolated stage failed: '+label)
        item['status']='passed'
        return stdout
    except subprocess.TimeoutExpired as error:
        stdout=error.output or '';stderr=error.stderr or '';timed_out=True
        item['status']='timed_out';raise RuntimeError('Isolated stage timed out: '+label)
    except Exception:
        item['status']='failed';raise
    finally:
        item['process_cleanup']=cleanup_job(label,job)
        # Once descendants are reaped, collect any final buffered bytes. None of
        # these raw streams is copied to the artifact or exception message.
        if item['process_cleanup']['all_reaped']:
            try:stdout,stderr=job.process.communicate(timeout=.5)
            except (subprocess.TimeoutExpired,ValueError):pass
        item['diagnostics']=summarize(stdout,stderr,source_paths,exit_code=job.process.returncode,timed_out=timed_out)
        if not item['process_cleanup']['all_reaped']:raise RuntimeError('Owned processes remain after stage: '+label)

"""Bounded database outage/recovery check for the isolated Docker lab."""
import json
from pathlib import Path
import subprocess
import time

def status(path):
    return int(subprocess.check_output(['curl','-ks','--max-time','15','--resolve','videoq.localhost:18443:127.0.0.1','-o','/dev/null','-w','%{http_code}','https://videoq.localhost:18443/'+path]))

def docker(action):
    subprocess.run(['sudo','-n','docker',action,'videoq-lti-lab-db-1'],check=True,stdout=subprocess.DEVNULL)

result={'before':{'health':status('health'),'ready':status('ready')}}
assert result['before']=={'health':200,'ready':200}
try:
    docker('stop')
    result['during']={'health':status('health'),'ready':status('ready')}
    assert result['during']=={'health':200,'ready':503},result
finally:
    docker('start')
    for attempt in range(30):
        if status('ready')==200:break
        time.sleep(1)
    else:raise RuntimeError('Lab database did not recover')
result['after']={'health':status('health'),'ready':status('ready')}
assert result['after']=={'health':200,'ready':200}
Path('/tmp/videoq-lti-lab/acceptance/readiness.json').write_text(json.dumps(result,indent=2))
print('Database outage returns readiness 503; liveness remains 200; readiness recovers without restarting the API.')

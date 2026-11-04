"""Local-lab operations. VideoQ is a separately managed HTTPS service."""
from contextlib import contextmanager
import ipaddress
import json
import os
from pathlib import Path
import ssl
import subprocess
import time
import urllib.error
import urllib.request

DATA = Path(os.environ.get('LTI_LAB_DIR', '/tmp/videoq-lti-lab'))
MOODLE = os.environ.get('MOODLE_URL', 'https://moodle.localhost:18443').rstrip('/')
VIDEOQ = os.environ.get('VIDEOQ_URL', 'https://videoq.localhost:18443').rstrip('/')
MOODLE_CONTAINER = os.environ.get('MOODLE_CONTAINER', 'videoq-lti-lab-moodle-1')
DB_CONTAINER = os.environ.get('LAB_DB_CONTAINER', 'videoq-lti-lab-db-1')

def docker(*args, input=None, timeout=120):
    result = subprocess.run(['sudo', '-n', 'docker', *args], input=input, capture_output=True, text=True, timeout=timeout)
    if result.returncode:
        # Command bodies and container environment may contain credentials.
        raise RuntimeError('Lab container operation failed; inspect its sanitized service logs')
    return result.stdout.strip()

def php(script, action=None, input=None, user=None):
    args = ['exec', '-i']
    if user:
        args += ['-u', user]
    args += [MOODLE_CONTAINER, 'php', '/lab/' + script]
    if action:
        args.append(action)
    text = docker(*args, input=json.dumps(input) if input is not None else None)
    return json.loads(text) if text else None

def guard_lab():
    if MOODLE != 'https://moodle.localhost:18443' or VIDEOQ != 'https://videoq.localhost:18443':
        raise RuntimeError('Mutating acceptance tests require the isolated local lab')

def database_url(database='videoq'):
    guard_lab()
    override = os.environ.get('VIDEOQ_TEST_DATABASE_URL' if database == 'videoq' else 'MOODLE_TEST_DATABASE_URL')
    if override:
        return override
    networks = json.loads(docker('inspect', '--format', '{{json .NetworkSettings.Networks}}', DB_CONTAINER))
    address = next(iter(networks.values()))['IPAddress']
    if not ipaddress.ip_address(address).is_private:
        raise RuntimeError('Private lab database required')
    return f'postgresql://postgres:lti-lab-only@{address}:5432/{database}'

@contextmanager
def database(name='videoq', autocommit=True):
    import psycopg
    from psycopg.rows import dict_row
    with psycopg.connect(database_url(name), autocommit=autocommit, row_factory=dict_row) as connection:
        yield connection

def query(sql, params=(), name='videoq'):
    with database(name) as connection:
        cursor = connection.execute(sql, params)
        return cursor.fetchall() if cursor.description else []

def save_report(name, values):
    target = DATA / 'acceptance' / (name + '.json')
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(json.dumps(values, ensure_ascii=False, indent=2, default=str) + '\n')
    print(name + ': passed')

def wait_for(callback, seconds=120, interval=1):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        value = callback()
        if value:
            return value
        time.sleep(interval)
    raise TimeoutError('Acceptance condition did not become true')

def tls_context():
    context = ssl.create_default_context()
    if MOODLE.endswith('.localhost:18443'):
        bundle = docker('exec', MOODLE_CONTAINER, 'cat', '/var/www/moodledata/moodleorgca.crt')
        context.load_verify_locations(cadata=bundle)
    return context

def http(url, body=None, headers=None):
    headers = headers or {}
    if body is not None:
        headers = {'Content-Type': 'application/json', **headers}
        body = json.dumps(body).encode()
    request = urllib.request.Request(url, data=body, headers=headers)
    try:
        with urllib.request.urlopen(request, context=tls_context(), timeout=30) as response:
            return response.status, dict(response.headers), response.read()
    except urllib.error.HTTPError as error:
        return error.code, dict(error.headers), error.read()

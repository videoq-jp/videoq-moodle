"""Real PHP service-auth/export contracts in a disposable Moodle transaction."""
import base64
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import uuid
from videoq_lab.runtime import DATA, MOODLE, MOODLE_CONTAINER, docker, guard_lab, php, query, save_report

def encode(value):
    return base64.urlsafe_b64encode(value).rstrip(b'=')

def main():
    guard_lab()
    before = query('SELECT id,status,file FROM videos ORDER BY id')
    configuration = query("SELECT name,value FROM mdl_config_plugins WHERE plugin='local_videoq' ORDER BY name", name='moodle')
    fixture = None
    with tempfile.TemporaryDirectory(prefix='videoq-materials-') as directory:
        key = Path(directory) / 'fixture.pem'
        generated = subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048'], capture_output=True, check=True)
        descriptor = os.open(key, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(descriptor, 'wb') as stream:
            stream.write(generated.stdout)
        public = subprocess.run(['openssl','pkey','-in',str(key),'-pubout'],capture_output=True,check=True).stdout.decode()
        media = DATA / 'acceptance' / 'spoken-lesson.mp4'
        assert media.is_file(), 'Provide the original spoken lab fixture'
        docker('cp', str(media), MOODLE_CONTAINER + ':/tmp/videoq-materials-spoken.mp4')
        try:
            fixture = php('materials-fixture.php','setup', {'run':uuid.uuid4().hex,'publicKey':public})
            now = int(time.time())
            claims = dict(iss=fixture['clientid'],sub=fixture['clientid'],aud=MOODLE+'/local/videoq/token.php',iat=now,exp=now+60,jti=uuid.uuid4().hex)
            claims['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] = str(fixture['typeid'])
            def signed(values):
                signing = encode(b'{"alg":"RS256","typ":"JWT"}') + b'.' + encode(json.dumps(values,separators=(',',':')).encode())
                signature = subprocess.run(['openssl','dgst','-sha256','-sign',str(key)],input=signing,capture_output=True,check=True).stdout
                return (signing+b'.'+encode(signature)).decode()
            result = php('check-materials-service.php', input={'jwt':signed(claims),
                         'wrongAudience':signed(dict(claims,aud='https://attacker.example/token',jti=uuid.uuid4().hex))})
            assert result['passed'] and result['transactionRolledBack']
        finally:
            if fixture:
                assert php('materials-fixture.php','cleanup')['removed']
            docker('exec', MOODLE_CONTAINER, 'php', '-r', "if(is_file('/tmp/videoq-materials-spoken.mp4'))unlink('/tmp/videoq-materials-spoken.mp4');")
        assert query('SELECT id,status,file FROM videos ORDER BY id') == before
        assert query("SELECT name,value FROM mdl_config_plugins WHERE plugin='local_videoq' ORDER BY name", name='moodle') == configuration
        save_report('materials-service-contract', result)

if __name__ == '__main__':
    main()

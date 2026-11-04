"""Restore Moodle filedir into a private scratch directory and verify the uploaded media bytes."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tarfile

root = Path('/tmp/videoq-lti-lab/acceptance')
private = root / 'backups'
private.mkdir(mode=0o700, exist_ok=True)
fixture = json.loads((root / 'moodle-upload.json').read_text())
php = 'define("CLI_SCRIPT",true);require "config.php"; $f=\\local_videoq\\synchronizer::video_file(' + str(int(fixture['cmid'])) + ');echo json_encode(["hash"=>$f->get_contenthash(),"bytes"=>$f->get_filesize()]);'
info = json.loads(subprocess.check_output(['sudo','-n','docker','exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','-r',php]))
assert len(info['hash']) == 40 and all(c in '0123456789abcdef' for c in info['hash'])
archive = private / 'moodle-filedir.tar.gz'
fd = os.open(archive,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600)
try:
    subprocess.run(['sudo','-n','docker','exec','videoq-lti-lab-moodle-1','tar','-czf','-','-C','/var/www/moodledata','filedir'],stdout=fd,stderr=subprocess.PIPE,check=True,timeout=120)
finally:
    os.close(fd)
restored = private / 'moodle-files-restored'
restored.mkdir(mode=0o700,exist_ok=True)
with tarfile.open(archive,'r:gz') as backup:
    backup.extractall(restored,filter='data')
verified_files = 0
for candidate in (restored / 'filedir').rglob('*'):
    if not candidate.is_file() or len(candidate.name) != 40 or any(c not in '0123456789abcdef' for c in candidate.name):
        continue
    digest = hashlib.sha1()
    with candidate.open('rb') as content:
        for block in iter(lambda: content.read(1024*1024), b''):
            digest.update(block)
    assert digest.hexdigest() == candidate.name, 'Restored Moodle content hash mismatch'
    verified_files += 1
assert verified_files > 0
media = restored / 'filedir' / info['hash'][:2] / info['hash'][2:4] / info['hash']
def sha256(path):
    h=hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda:stream.read(1024*1024),b''):h.update(chunk)
    return h.hexdigest()
expected=sha256(root / 'spoken-lesson-100m.mp4')
assert sha256(media)==expected
s3=json.loads((root / 'media-backup.json').read_text())
assert s3['sha256']==expected and s3['bytes']==int(info['bytes'])==104857600
(root / 'moodle-files-backup.json').write_text(json.dumps({'moodleFiledirRestored':True,'verifiedContentFiles':verified_files,'sourceAndVideoqCopyMatch':True,'sha256':expected,'bytes':info['bytes'],'archiveBytes':archive.stat().st_size},indent=2))
print('Moodle filedir restore matches the original upload and restored VideoQ media copy.')

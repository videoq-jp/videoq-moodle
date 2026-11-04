"""Restore Moodle filedir and a disposable S3 copy; compare actual media bytes."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tarfile
from videoq_lab.runtime import DATA, MOODLE_CONTAINER, docker, guard_lab, query, save_report

def digest(path, algorithm='sha256'):
    value = hashlib.new(algorithm)
    with path.open('rb') as stream:
        for block in iter(lambda:stream.read(1024*1024),b''):
            value.update(block)
    return value.hexdigest()

def main():
    guard_lab()
    resource = query("SELECT video_id FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id='82'")[0]
    video = query('SELECT file FROM videos WHERE id=%s',(resource['video_id'],))[0]
    script = '''define('CLI_SCRIPT',true);require '/var/www/html/config.php';
      $file=\\local_videoq\\synchronizer::video_file(82);
      if(!$file)throw new Exception('Original source missing');
      echo json_encode(['hash'=>$file->get_contenthash(),'bytes'=>$file->get_filesize()]);'''
    info = json.loads(docker('exec',MOODLE_CONTAINER,'php','-r',script))
    assert len(info['hash']) == 40 and all(c in '0123456789abcdef' for c in info['hash'])
    private = DATA / 'acceptance' / 'backups'
    private.mkdir(parents=True,mode=0o700,exist_ok=True)
    archive = private / 'moodle-filedir.tar.gz'
    fd = os.open(archive,os.O_CREAT|os.O_TRUNC|os.O_WRONLY,0o600)
    os.fchmod(fd,0o600)
    with os.fdopen(fd,'wb') as output:
        subprocess.run(['sudo','-n','docker','exec',MOODLE_CONTAINER,'tar','-czf','-','-C','/var/www/moodledata','filedir'],
                       stdout=output,stderr=subprocess.PIPE,check=True,timeout=300)
    restored = private / 'moodle-files-restored'
    restored.mkdir(mode=0o700,exist_ok=True)
    with tarfile.open(archive,'r:gz') as backup:
        backup.extractall(restored,filter='data')
    count = 0
    for path in (restored / 'filedir').rglob('*'):
        if not path.is_file() or len(path.name) != 40 or any(c not in '0123456789abcdef' for c in path.name):
            continue
        assert digest(path,'sha1') == path.name, 'Restored Moodle content hash mismatch'
        count += 1
    source = restored / 'filedir' / info['hash'][:2] / info['hash'][2:4] / info['hash']
    worker = os.environ.get('VIDEOQ_TEST_WORKER','videoq-runtime-worker-1')
    object_check = Path(__file__).with_name('verify-object-restore.py').read_text()
    s3 = json.loads(docker('exec','-i',worker,'python','-',video['file'],input=object_check,timeout=300))
    assert count > 0 and s3['sha256'] == digest(source) and s3['bytes'] == source.stat().st_size == int(info['bytes'])
    save_report('media-backup',dict(passed=True,moodleFiledirRestored=True,verifiedContentFiles=count,
                sourceAndVideoqCopyMatch=True,sha256=s3['sha256'],bytes=s3['bytes'],disposableS3CopyRemoved=True))

if __name__ == '__main__':
    main()

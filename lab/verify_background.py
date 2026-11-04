"""Real cron + external VideoQ scheduler/worker: export, indexing and deletion.

Requires a spoken fixture (--media) and a separately managed VideoQ worker with
the lab S3 environment. Uses that worker's installed Python SDK, no core source
imports or manual processing jobs. Real inference may incur provider charges.
"""
import argparse
import json
import os
from pathlib import Path
import subprocess
from videoq_lab.runtime import DATA, MOODLE_CONTAINER, docker, guard_lab, php, query, save_report, wait_for

def resource(source):
    rows = query("""SELECT r.video_id,r.course_id,v.status,v.file,length(v.transcript) AS transcript_chars,
        (SELECT count(*) FROM scene_embeddings e WHERE e.video_id=r.video_id) AS scenes
        FROM lms_resources r LEFT JOIN videos v ON v.id=r.video_id
        WHERE r.installation_id='moodle-sync-lab' AND r.external_id=%s""", (str(source['cmid']),))
    return rows[0] if rows else None

def object_exists(filename):
    worker = os.environ.get('VIDEOQ_TEST_WORKER', 'videoq-runtime-worker-1')
    script = Path(__file__).with_name('check-sync-object.py').read_text()
    return json.loads(docker('exec', '-i', worker, 'python', '-', filename, input=script))['exists']

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--media', type=Path, default=DATA / 'acceptance' / 'spoken-lesson.mp4')
    args = parser.parse_args()
    guard_lab()
    assert args.media.is_file(), 'Provide an original spoken MP4 fixture'
    before = query('SELECT id,file,status FROM videos ORDER BY id')
    source = video = None
    docker('cp', str(args.media), MOODLE_CONTAINER + ':/tmp/videoq-materials-acceptance.mp4')
    try:
        source = php('live-material-fixture.php', 'create')
        def completed():
            current = resource(source)
            assert not current or current['status'] != 'error', 'External worker failed'
            return current if current and current['status'] == 'completed' and current['transcript_chars'] and current['scenes'] and php('live-material-fixture.php', 'status')['status'] == 'completed' else None
        video = wait_for(completed, seconds=420, interval=2)
        original = query("SELECT course_id FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id='82'")[0]
        assert video['course_id'] == original['course_id'], 'Export must join the existing course'
        assert object_exists(video['file']), 'Exported media object missing'
        from verify_course import check_course
        check_course(video['video_id'], source['cmid'])
        print('Normal cron and worker: transcript, embeddings and course membership verified.')
    finally:
        if source:
            php('live-material-fixture.php', 'delete')
            def deleted():
                current = resource(source)
                return (not current or current['video_id'] is None) and php('live-material-fixture.php', 'status')['status'] == 'disabled'
            wait_for(deleted, seconds=240, interval=2)
            php('live-material-fixture.php', 'cleanup')
            query("DELETE FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id=%s AND video_id IS NULL", (str(source['cmid']),))
            docker('exec', MOODLE_CONTAINER, 'php', '-r', "unlink('/tmp/videoq-materials-acceptance.mp4');")
            assert query('SELECT id,file,status FROM videos ORDER BY id') == before, 'Existing videos must be preserved'
    assert video
    wait_for(lambda: not object_exists(video['file']), seconds=180, interval=2)
    save_report('materials-background', dict(passed=True, normalMoodleCron=True, normalVideoqScheduler=True,
                normalSqsWorker=True, sameExistingCourse=True, sourceDeletionAcknowledged=True,
                retiredMediaDeleted=True, existingVideosPreserved=True))

if __name__ == '__main__':
    main()

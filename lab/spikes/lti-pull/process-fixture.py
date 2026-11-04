"""Run existing worker tasks for one guarded spike video using deterministic HTTP inference."""
import json
import os
import sys

if os.environ.get('DATABASE_URL') != 'postgresql://postgres:lti-lab-only@db:5432/videoq':
    raise RuntimeError('Isolated lab only')
video_id = int(sys.argv[1])
if len(sys.argv) != 3 or not sys.argv[2].startswith('http://queue:9324/') or 'videoq-pull-spike-' not in sys.argv[2]:
    raise RuntimeError('An isolated spike queue is required')
os.environ['SQS_QUEUE_URL'] = sys.argv[2]
sys.path.insert(0, '/app')
os.environ.update({
    'WHISPER_BACKEND': 'local', 'WHISPER_LOCAL_URL': 'http://ai:8080/v1', 'OPENAI_API_KEY': 'lab',
    'EMBEDDING_PROFILE': 'openai', 'EMBEDDING_BASE_URL': 'http://ai:8080/v1',
    'EMBEDDING_API_KEY': 'lab', 'EMBEDDING_MODEL': 'text-embedding-3-small',
    'PGVECTOR_COLLECTION_NAME': 'scene_embeddings', 'VIDEO_VISUAL_ENABLED': 'false',
})
from worker_python.db import db_connection
from worker_python import transcription_checkpoints as checkpoints
from worker_python.video_sql import get_video_for_task
from worker_python.tasks.segmented_transcription import start_uploaded_transcription, transcribe_video_chunk, finish_video_transcription
from worker_python.tasks.indexing import index_video_transcript

with db_connection() as connection:
    source = connection.execute("SELECT 1 FROM lms_resources WHERE video_id=%s AND installation_id LIKE 'pull-spike-%%'", (video_id,)).fetchone()
    if not source:
        raise RuntimeError('Only a disposable spike video may be processed')
    video = get_video_for_task(connection, video_id, include_transcript=False)
if video.status != 'completed':
    run = checkpoints.load_run(video_id)
    if not run:
        start_uploaded_transcription(video)
        run = checkpoints.load_run(video_id)
    if not run:
        raise RuntimeError('No transcription plan')
    for index in range(run.chunk_count):
        transcribe_video_chunk(video_id, run.run_id, index)
    finish_video_transcription(video_id, run.run_id)
    index_video_transcript(video_id)
print(json.dumps({'videoId': video_id, 'workerPipeline': True, 'inference': 'deterministic-local-fixture'}))

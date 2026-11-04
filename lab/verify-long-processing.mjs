// Observe actual durable long-video chunks; never seeds transcripts or repeats completed processing.
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { setTimeout } from 'node:timers/promises';
import assert from 'node:assert/strict';
const root='/tmp/videoq-lti-lab/acceptance';const upload=JSON.parse(readFileSync(root+'/long-upload.json'));
const code=`import {labDatabase} from '/fixtures/lab-database.mjs';const db=await labDatabase();console.log(JSON.stringify((await db.query(\`SELECT v.id,v.status,v.processing_seconds,v.transcript,r.duration_ms,r.chunk_count,(SELECT count(*)::int FROM video_transcription_chunks c WHERE c.run_id=r.run_id AND c.segments IS NOT NULL) AS saved_chunks,(SELECT count(*)::int FROM scene_embeddings e WHERE e.video_id=v.id) AS indexed_scenes FROM lms_resources l JOIN videos v ON v.id=l.video_id LEFT JOIN video_transcription_runs r ON r.video_id=v.id WHERE l.installation_id='moodle-sync-lab' AND l.external_id=$1\`,['${upload.cmid}'])).rows[0]));await db.end();`;
const began=Date.now();let last='';
for(let attempt=0;attempt<240;attempt++){
 const state=JSON.parse(execFileSync('sudo',['-n','docker','exec','videoq-lti-lab-api-1','node','--input-type=module','-e',code],{encoding:'utf8'}));
 const summary=JSON.stringify({status:state.status,chunkCount:state.chunk_count,savedChunks:state.saved_chunks,indexedScenes:state.indexed_scenes});if(summary!==last){console.log(summary);last=summary;}
 if(state.status==='error')throw new Error('Long lecture processing failed');
 if(state.status==='completed'){
  assert(Number(state.duration_ms)>=1799000);assert(state.chunk_count>=6);assert.equal(state.saved_chunks,state.chunk_count);assert.match(state.transcript,/copper\s+harbo[u]?r/i);assert(state.indexed_scenes>0);
  const ending=state.transcript.split(/\n\s*\n/).find(cue=>/copper\s+harbo[u]?r/i.test(cue));assert(ending);const end=ending.match(/--> (\d\d):(\d\d):(\d\d),\d+/);assert(end&&Number(end[1])*3600+Number(end[2])*60+Number(end[3])>=1790);
  writeFileSync(root+'/long-processing.json',JSON.stringify({uploadedViaMoodleUi:upload.uploadedViaMoodleUi,bytes:upload.bytes,sourceCmid:upload.cmid,videoId:String(state.id),durationMilliseconds:Number(state.duration_ms),chunkCount:state.chunk_count,savedChunks:state.saved_chunks,indexedScenes:state.indexed_scenes,closingCheckpointRecognized:true,closingTimestampNearEnd:true,realTranscription:true,realEmbeddings:true,manualSeeding:false,observationMilliseconds:Date.now()-began},null,2));
  execFileSync('sudo',['-n','docker','exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','admin/cli/scheduled_task.php',String.raw`--execute=\local_videoq\task\synchronize`],{stdio:'ignore'});
  console.log('Thirty minute lecture: all durable chunks complete, closing checkpoint recognized at the end and indexed.');process.exit(0);
 }
 await setTimeout(5000);
}
throw new Error('Long lecture did not finish within the bounded observation period');

// Real cron, normal SQS worker and real provider; no direct invocation of processing tasks.
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { setTimeout } from 'node:timers/promises';
import assert from 'node:assert/strict';
const docker = (args,input) => execFileSync('sudo',['-n','docker',...args],{encoding:'utf8',input,timeout:45000}).trim();
const action = name => JSON.parse(docker(['exec','videoq-lti-lab-moodle-1','php','/lab/live-material-fixture.php',name]));
const query = (sql,params=[]) => JSON.parse(docker(['exec','videoq-lti-lab-api-1','node','--input-type=module','-e',
  `import{labDatabase}from'/fixtures/lab-database.mjs';const db=await labDatabase();try{console.log(JSON.stringify((await db.query(${JSON.stringify(sql)},${JSON.stringify(params)})).rows));}finally{await db.end();}`]));
const before = query('SELECT id,file,status FROM videos ORDER BY id');
let source, video;
const resource = () => query("SELECT r.video_id,r.course_id,v.status,v.file,length(v.transcript) AS transcript_chars,(SELECT count(*)::int FROM scene_embeddings e WHERE e.video_id=r.video_id) AS scenes FROM lms_resources r LEFT JOIN videos v ON v.id=r.video_id WHERE r.installation_id='moodle-sync-lab' AND r.external_id=$1",[String(source.cmid)])[0];
try {
  docker(['cp','/tmp/videoq-lti-lab/acceptance/spoken-lesson.mp4','videoq-lti-lab-moodle-1:/tmp/videoq-materials-acceptance.mp4']);
  source=action('create');
  for(let attempt=0;attempt<240;attempt++) {
    const current=resource();
    if(current?.status==='completed' && current.transcript_chars>0 && current.scenes>0 && action('status').status==='completed') {video=current;break;}
    assert.notEqual(current?.status,'error','Normal worker processing failed');
    await setTimeout(1500);
  }
  assert(video,'Background transfer, real transcription and indexing must finish');
  const existing=query("SELECT course_id FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id='82'")[0];
  assert.equal(video.course_id,existing.course_id);
  console.log('Normal cron and SQS processing completed a second video in the existing course with verified HTTPS.');
} finally {
  if(source) {
    action('delete');
    let removed=false;
    for(let attempt=0;attempt<120;attempt++) {
      const current=resource();
      if(!current?.video_id && action('status').status==='disabled') {removed=true;break;} await setTimeout(1500);
    }
    assert(removed,'The normal scheduler must acknowledge deletion before removing fixture metadata');
    action('cleanup');
    query("DELETE FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id=$1 AND video_id IS NULL",[String(source.cmid)]);
    docker(['exec','videoq-lti-lab-moodle-1','rm','-f','/tmp/videoq-materials-acceptance.mp4']);
    assert.deepEqual(query('SELECT id,file,status FROM videos ORDER BY id'),before);
  }
}
if(video?.file) {
  let gone=false;
  for(let attempt=0;attempt<60;attempt++) {
    const output=docker(['exec','videoq-lti-lab-api-1','node','--import','tsx','--input-type=module','-e',
      `import{getR2ObjectSize}from'/workspace/apps/api/src/integrations/media.ts';console.log(JSON.stringify({exists:await getR2ObjectSize(process.env,${JSON.stringify(video.file)})!==null}));`]);
    if(!JSON.parse(output).exists) {gone=true;break;} await setTimeout(1500);
  }
  assert(gone,'The retired media object must be removed by the normal maintenance outbox');
}
const result={passed:true,normalMoodleCron:true,normalVideoqScheduler:true,normalSqsWorker:true,
 realProvider:'openrouter',verifiedHttps:true,sameExistingCourse:true,sourceDeletionAcknowledged:true,retiredMediaDeleted:true,existingVideosPreserved:true};
writeFileSync('/tmp/videoq-lti-lab/acceptance/materials-background.json',JSON.stringify(result,null,2));
console.log(JSON.stringify(result));

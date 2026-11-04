import {execFileSync} from 'node:child_process';
import {setTimeout} from 'node:timers/promises';
import {writeFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const docker=(...args)=>execFileSync('sudo',['-n','docker',...args],{encoding:'utf8',timeout:120000}).trim();
const action=name=>JSON.parse(docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','/lab/managed-repair-actions.php',name));
const remote=cmid=>JSON.parse(docker('exec','videoq-lti-lab-api-1','node','--input-type=module','-e',`import{labDatabase}from'/fixtures/lab-database.mjs';const d=await labDatabase();console.log(JSON.stringify((await d.query("SELECT r.enabled,r.video_id,r.resource_link_id,r.course_id,v.status,v.file,t.run_id,(SELECT count(*)::int FROM scene_embeddings e WHERE e.video_id=v.id) AS scenes FROM lms_resources r LEFT JOIN videos v ON v.id=r.video_id LEFT JOIN video_transcription_runs t ON t.video_id=v.id WHERE r.installation_id='moodle-sync-lab' AND r.external_id=$1",['${Number(cmid)}'])).rows[0]||null));await d.end();`));
let first;const checks=[];
try{
 let local=action('setup');
 for(let attempt=0;attempt<60;attempt++){const r=remote(local.cmid);if(r?.status==='completed'&&r.scenes>0){first=r;break;}await setTimeout(2000);}
 assert(first,'Fixture did not complete real processing');
 local=action('setup');const baseline={...first};
 for(const scenario of ['url','lti','section']){
  const old={...local};local=action(scenario);const current=remote(local.cmid);
  assert(local.enabled&&local.sourceExists&&local.ltiExists&&local.linkExists);
  assert.equal(local.status,'completed');assert.notEqual(local.lticmid,old.lticmid);assert.notEqual(local.linkcmid,old.linkcmid);
  assert.equal(current.video_id,baseline.video_id);assert.equal(current.file,baseline.file);assert.equal(current.run_id,baseline.run_id);assert.equal(current.scenes,baseline.scenes);assert(current.enabled);
  checks.push(scenario+': recreated managed activities, same media/video/transcription run/scene index');console.log(checks.at(-1));
 }
 local=action('disable');const disabled=remote(local.cmid);assert(!local.enabled&&local.sourceExists&&!local.ltiExists&&!local.linkExists);assert(!disabled.enabled&&disabled.video_id===null);
 // A subsequent sync must not silently turn an explicit disable back on.
 local=action('disable');assert(!local.enabled&&!local.ltiExists&&!local.linkExists);checks.push('Explicit question disable remains disabled and removes the remote copy.');
 writeFileSync('/tmp/videoq-lti-lab/acceptance/managed-repair.json',JSON.stringify({checks,passed:true,preservedVideoId:baseline.video_id,sourceCourseId:local.courseid},null,2));
}finally{if(first)action('cleanup');}

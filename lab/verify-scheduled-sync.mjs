// All synchronization and physical cleanup must be done by the running periodic services.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync,writeFileSync } from 'node:fs';
import { setTimeout } from 'node:timers/promises';
import assert from 'node:assert/strict';
const dir='/tmp/videoq-lti-lab/acceptance';const upload=JSON.parse(readFileSync(dir+'/automatic-upload.json'));
const docker=(...args)=>execFileSync('sudo',['-n','docker',...args],{encoding:'utf8',timeout:60000});
const state=()=>JSON.parse(docker('exec','videoq-lti-lab-api-1','node','/fixtures/sync-lab-state.mjs',String(upload.cmid)));
const local=()=>JSON.parse(docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','/lab/sync-actions.php','state',String(upload.cmid)));
const browser=await chromium.launch({headless:true,args:['--no-proxy-server']});
try{
 const teacher=await browser.newPage({ignoreHTTPSErrors:true});await teacher.goto('https://moodle.localhost:18443/login/index.php');await teacher.locator('#username').fill('teacher');await teacher.locator('.toggle-sensitive-wrapper #password').waitFor({state:'attached'});await teacher.locator('#password').fill('Lti-lab-only-123!');await Promise.all([teacher.waitForURL(url=>!url.pathname.includes('/login/')),teacher.locator('#loginbtn').click()]);
 await teacher.goto('https://moodle.localhost:18443/local/videoq/manage.php?cmid='+upload.cmid);
 await Promise.all([teacher.waitForNavigation(),teacher.getByRole('button',{name:'質問機能を有効にする',exact:true}).click()]);
 const began=Date.now();let ready;
 for(let attempt=0;attempt<60;attempt++){
  const current=state();if(current.resource?.status==='completed'&&local()?.status==='completed'){ready=current;break;}
  if(attempt%6===0)console.log('Waiting for periodic synchronization:',current.resource?.status||'not transferred');
  await setTimeout(3000);
 }
 assert(ready,'Periodic task did not finish synchronization');assert.match(ready.resource.transcript,/green\s+compass/i);assert(ready.resource.indexed_scenes>0);
 const synchronizationMs=Date.now()-began;const file=ready.resource.file;
 const learner=await browser.newPage({ignoreHTTPSErrors:true});await learner.goto('https://moodle.localhost:18443/login/index.php');await learner.locator('#username').fill('learner');await learner.locator('.toggle-sensitive-wrapper #password').waitFor({state:'attached'});await learner.locator('#password').fill('Lti-lab-only-123!');await Promise.all([learner.waitForURL(url=>!url.pathname.includes('/login/')),learner.locator('#loginbtn').click()]);
 await learner.goto('https://moodle.localhost:18443/local/videoq/view.php?course=2');await learner.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false);assert.equal(await learner.locator('#videoq-lesson option').count(),4);
 docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','-r',`define('CLI_SCRIPT',true);require 'config.php';require_once($CFG->dirroot.'/course/lib.php');$cm=get_coursemodule_from_id('resource',${upload.cmid},2,false,MUST_EXIST);if($CFG->wwwroot!=='https://moodle.localhost:18443'||$cm->name!=='検証用：定期実行のみで同期（検証後削除）')throw new Exception('Not the disposable fixture');\\core\\session\\manager::set_user(get_admin());course_delete_module($cm->id,false);`);
 const deleted=Date.now();let clean=false;
 for(let attempt=0;attempt<60;attempt++){
  const current=state();if(current.resource&&!current.resource.enabled&&current.resource.video_id===null&&current.inventory.pending_cleanup===0){clean=true;break;}
  await setTimeout(3000);
 }
 assert(clean,'Periodic services did not remove the deleted source');
 const object=JSON.parse(docker('exec','videoq-lti-lab-worker-1','python','/lab/check-sync-object.py',file));assert.equal(object.exists,false);
 await learner.reload();await learner.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false);assert.equal(await learner.locator('#videoq-lesson option').count(),3);
 writeFileSync(dir+'/scheduled-sync.json',JSON.stringify({sourceCmid:upload.cmid,uploadedViaMoodleUi:true,enabledViaMoodleUi:true,explicitCronCommands:0,explicitMaintenanceCommands:0,realTranscription:true,realEmbeddings:true,automaticallyAddedToSharedCourse:true,automaticallyRemovedFromSharedCourse:true,physicalMediaCleanup:true,synchronizationMilliseconds:synchronizationMs,deletionMilliseconds:Date.now()-deleted},null,2));
 console.log('Periodic services transferred, transcribed and indexed the Moodle upload, then physically removed its deleted source; no manual cron/maintenance commands.');
}finally{await browser.close();}

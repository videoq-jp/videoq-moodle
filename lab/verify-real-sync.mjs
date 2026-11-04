// Real speech + real OpenRouter acceptance. Source is uploaded via Moodle UI first.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { setTimeout } from 'node:timers/promises';
import assert from 'node:assert/strict';
import { readLtiAnswer } from './read-lti-answer.mjs';
const dir = '/tmp/videoq-lti-lab';
const upload = JSON.parse(readFileSync(dir + '/acceptance/moodle-upload.json'));
const args = ['-n','docker','compose','-p','videoq-lti-lab','--env-file',dir+'/compose.env','-f','lab/compose.yaml','-f','lab/compose.sync.yaml','-f','lab/compose.live-sync.yaml'];
const dc = (...command) => execFileSync('sudo', [...args,...command], { encoding:'utf8',timeout:120000 });
const state = () => JSON.parse(dc('exec','-T','api','node','/fixtures/sync-lab-state.mjs',String(upload.cmid)));
const moodle = () => JSON.parse(dc('exec','-T','moodle','php','/lab/sync-actions.php','state',String(upload.cmid)));
const cron = () => dc('exec','-T','moodle','php','admin/cli/scheduled_task.php',String.raw`--execute=\local_videoq\task\synchronize`);
const checks=[];
const check = text => { checks.push(text); console.log(text); };
const browser = await chromium.launch({headless:true,args:['--no-proxy-server']});
const context = await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1440,height:1000}});
async function login(username) {
 const actor=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1440,height:1000}});const page=await actor.newPage();await page.goto('https://moodle.localhost:18443/login/index.php');
 await page.locator('#username').fill(username);await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
 await Promise.all([page.waitForURL(url=>!url.pathname.includes('/login/')),page.locator('#loginbtn').click()]);return page;
}
async function apiReady(){
 for(let i=0;i<30;i++){try{const response=await context.request.get('https://videoq.localhost:18443/ready',{timeout:1000});if(response.status()===200)return;}catch{}await setTimeout(500);}
 throw new Error('API did not become ready');
}
let first;
try{
 const teacher=await login('teacher');await teacher.goto('https://moodle.localhost:18443/local/videoq/manage.php?cmid='+upload.cmid);
 if(await teacher.getByRole('button',{name:'質問機能を有効にする',exact:true}).count())await Promise.all([teacher.waitForNavigation(),teacher.getByRole('button',{name:'質問機能を有効にする',exact:true}).click()]);
 check('Teacher enables VideoQ for the 100 MiB video uploaded through Moodle.');
 const before=state();
 if(!before.resource){
  dc('stop','worker');dc('stop','api');
  cron();assert.equal(moodle().status,'error');
  check('API outage is recorded as a retryable Moodle synchronization error.');
  dc('start','api');await apiReady();cron();
  const waiting=state();assert(waiting.resource);assert.notEqual(waiting.resource.status,'completed');
  check('Media transfer recovers after API restart and the job waits durably while the worker is stopped.');
  dc('start','worker');
 }
 const started=Date.now();let deadline=Date.now()+240000;
 while(Date.now()<deadline){
  first=state();if(first.resource?.status==='completed')break;
  if(first.resource?.status==='error')throw new Error('The real audio/embedding pipeline reported an error.');
  if(Date.now()-started>10000&&Math.floor((Date.now()-started)/1000)%10===0)console.log('Processing status:',first.resource?.status);
  await setTimeout(1000);
 }
 assert.equal(first?.resource?.status,'completed','Real audio processing must finish');cron();
 assert.match(first.resource.transcript,/silver\s+maple/i);
 assert(first.resource.indexed_scenes>0);assert(first.resource.processing_seconds>0);
 check('Real Whisper speech recognition produced the spoken silver-maple example and real embeddings.');
 const object=JSON.parse(dc('exec','-T','worker','python','/lab/check-sync-object.py',first.resource.file));assert.equal(object.exists,true);
 check('The transferred media object exists in real S3-compatible storage.');
 const stable=state();cron();assert.equal(state().resource.video_id,stable.resource.video_id);assert.equal(state().usage.used_upload_bytes,stable.usage.used_upload_bytes);
 check('A repeated cron run neither reuploads nor charges the source twice.');
 const learner=await login('learner');await learner.goto('https://moodle.localhost:18443/local/videoq/view.php?lesson=sync_'+upload.cmid);
 await learner.waitForFunction(()=>!document.querySelector('#videoq-native-controls').disabled);
 const chat=learner.locator('#videoq-native-chat');
 const responsePromise=learner.waitForResponse(response=>response.url().endsWith('/api/lti/messages/stream'));
 await chat.locator('[data-region="send-message-txt"]').fill('この教材の練習例につけられた英語の名前を、音声の表現どおりに答えてください。');
 await chat.locator('[data-action="send-message"]').click();
 const response=await responsePromise;assert.equal(response.status(),200);const result=await readLtiAnswer(response);
 await learner.waitForFunction(()=>!document.querySelector('#videoq-native-controls').disabled);
 const text=await chat.locator('.answer [data-region="text-container"]').innerText();assert.match(text,/silver\s+maple|シルバー.*メープル|銀.*カエデ/i);
 assert(result.answer.sources.some(source=>String(source.video_id)===String(first.resource.video_id)));
 await learner.waitForFunction(()=>!document.querySelector('#videoq-native-controls').disabled);
 await learner.screenshot({path:dir+'/acceptance/real-question.png',animations:'disabled'});
 check('A learner receives a real AI answer grounded in the automatically transcribed uploaded video.');
 const final=state();
 writeFileSync(dir+'/acceptance/real-sync.json',JSON.stringify({checks,sourceCmid:upload.cmid,uploadedViaMoodleUi:true,bytes:upload.bytes,provider:'OpenRouter',realSpeechRecognition:true,realEmbeddings:true,realAnswer:true,manualTranscriptOrVectorInsertion:false,videoId:first.resource.video_id,courseId:first.resource.course_id,indexedScenes:first.resource.indexed_scenes,transcript:first.resource.transcript,answer:text,processingObservedMilliseconds:Date.now()-started,initialUsage:before.usage,finalUsage:final.usage},null,2));
}catch(error){writeFileSync(dir+'/acceptance/real-sync-failure.json',JSON.stringify({checks,message:error.message},null,2));throw error;}
finally{dc('start','api','worker');await browser.close();}

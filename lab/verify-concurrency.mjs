// Five concurrent real learner questions; no additional VideoQ accounts are created.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { performance } from 'node:perf_hooks';
import assert from 'node:assert/strict';
import { readLtiAnswer } from './read-lti-answer.mjs';
const dir='/tmp/videoq-lti-lab';
const source=JSON.parse(readFileSync(dir+'/acceptance/moodle-upload.json'));
const args=['-n','docker','compose','-p','videoq-lti-lab','--env-file',dir+'/compose.env','-f','lab/compose.yaml','-f','lab/compose.sync.yaml','-f','lab/compose.live-sync.yaml'];
const dc=(...command)=>execFileSync('sudo',[...args,...command],{encoding:'utf8',timeout:60000});
const state=()=>JSON.parse(dc('exec','-T','api','node','/fixtures/sync-lab-state.mjs',String(source.cmid)));
const users=JSON.parse(dc('exec','-T','moodle','php','/lab/acceptance-users.php','create'));
const browser=await chromium.launch({headless:true,args:['--no-proxy-server']});
try{
 const before=state();assert.equal(before.resource.status,'completed');
 const pages=await Promise.all(users.map(async user=>{
  const context=await browser.newContext({ignoreHTTPSErrors:true});const page=await context.newPage();
  await page.goto('https://moodle.localhost:18443/login/index.php');await page.locator('#username').fill(user.username);await page.locator('.toggle-sensitive-wrapper #password').waitFor({ state: 'attached' });await page.locator('#password').fill('Lti-lab-only-123!');
  await Promise.all([page.waitForURL(url=>!url.pathname.includes('/login/')),page.locator('#loginbtn').click()]);
  await page.goto('https://moodle.localhost:18443/local/videoq/view.php?lesson=sync_'+source.cmid);
  await page.waitForFunction(()=>!document.querySelector('#videoq-native-controls').disabled,null,{timeout:60000});return {page,user};
 }));
 const start=performance.now();
 const results=await Promise.all(pages.map(async({page,user},index)=>{
  const root=page.locator('#videoq-native-chat');
  await root.locator('[data-region="send-message-txt"]').fill('同時受講者'+(index+1)+'の確認です。この教材の練習例の英語名だけを短く答えてください。');
  const responsePromise=page.waitForResponse(response=>response.url().endsWith('/api/lti/messages/stream'));
  await root.locator('[data-action="send-message"]').click();
  const response=await responsePromise;assert.equal(response.status(),200);const body=await readLtiAnswer(response);
  assert.match(body.answer.segments.map(segment=>segment.text).join(''),/silver\s+maple|シルバー.*メープル/i);
  await page.waitForFunction(()=>!document.querySelector('#videoq-native-controls').disabled);
  return {learner:index+1,httpStatus:response.status(),milliseconds:Math.round(performance.now()-start),sourceVideoIds:[...new Set(body.answer.sources.map(source=>source.video_id))]};
 }));
 const after=state();assert(after.usage.used_ai_answers>=before.usage.used_ai_answers+5);assert.equal(after.inventory.account_count,before.inventory.account_count);
 writeFileSync(dir+'/acceptance/concurrency.json',JSON.stringify({concurrentLearners:5,realAi:true,results,maximumMilliseconds:Math.max(...results.map(result=>result.milliseconds)),noAdditionalVideoQAccounts:true,answersCharged:after.usage.used_ai_answers-before.usage.used_ai_answers},null,2));
 console.log('Five concurrently authenticated Moodle learners received real course-scoped AI answers.');
}finally{await browser.close();dc('exec','-T','moodle','php','/lab/acceptance-users.php','cleanup');}

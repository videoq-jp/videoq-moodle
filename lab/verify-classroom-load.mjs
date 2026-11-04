// Real Moodle-authenticated learners; release the browser contexts before concurrent real SSE questions.
// This measures API/provider concurrency, not 100 simultaneous video decoders on this small lab host.
import { chromium, request } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync,writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { readLtiAnswer } from './read-lti-answer.mjs';
const dir='/tmp/videoq-lti-lab/acceptance';
const count=Number(process.env.LTI_LEARNER_COUNT||100);assert(Number.isInteger(count)&&count>=1&&count<=200);
const docker=(...args)=>execFileSync('sudo',['-n','docker',...args],{encoding:'utf8',timeout:120000});
const quotaCode=`import {labDatabase} from '/fixtures/lab-database.mjs';const db=await labDatabase();const before=(await db.query("SELECT ai_answers_limit,used_ai_answers FROM users WHERE id='lti-lab-owner'")).rows[0];if(${process.env.LTI_LAB_ALLOW_QUOTA_TOPUP==='1'})await db.query("UPDATE users SET ai_answers_limit=greatest(coalesce(ai_answers_limit,0),used_ai_answers+$1+20) WHERE id='lti-lab-owner'",[${count}]);const after=(await db.query("SELECT ai_answers_limit,used_ai_answers FROM users WHERE id='lti-lab-owner'")).rows[0];console.log(JSON.stringify({before,after}));await db.end();`;
const quota=JSON.parse(docker('exec','videoq-lti-lab-api-1','node','--input-type=module','-e',quotaCode));
assert(quota.after.ai_answers_limit-quota.after.used_ai_answers>=count,'Reserve enough lab-only answer quota before the load test');
const users=JSON.parse(docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','/lab/acceptance-users.php','create',String(count)));
const state=()=>JSON.parse(docker('exec','videoq-lti-lab-api-1','node','/fixtures/sync-lab-state.mjs','30'));
const before=state();const sessions=[];const browser=await chromium.launch({headless:true,args:['--no-proxy-server']});
const client=await request.newContext({ignoreHTTPSErrors:true,timeout:300000});
try{
 for(let offset=0;offset<users.length;offset+=2){
  const batch=await Promise.all(users.slice(offset,offset+2).map(async(user,index)=>{
   const context=await browser.newContext({ignoreHTTPSErrors:true});
   try{
    const page=await context.newPage();await page.goto('https://moodle.localhost:18443/login/index.php');await page.locator('#username').fill(user.username);await page.locator('.toggle-sensitive-wrapper #password').waitFor({state:'attached'});await page.locator('#password').fill('Lti-lab-only-123!');
    await Promise.all([page.waitForURL(url=>!url.pathname.includes('/login/')),page.locator('#loginbtn').click()]);
    const responsePromise=page.waitForResponse(r=>r.url().endsWith('/api/lti/session'));
    await page.goto('https://moodle.localhost:18443/local/videoq/view.php?course=2');const response=await responsePromise;assert.equal(response.status(),200);
    const session=await response.json();assert.equal(session.playback.videos.length,3);
    return {index:offset+index+1,token:session.token};
   }finally{await context.close();}
  }));sessions.push(...batch);if(sessions.length%10===0)console.log(sessions.length+' real learner sessions authenticated.');
 }
 await browser.close();
 const began=performance.now();let finished=0;
 const results=await Promise.all(sessions.map(async session=>{
  try{
   const response=await client.post('https://videoq.localhost:18443/api/lti/messages/stream',{headers:{Origin:'https://videoq.localhost:18443',Authorization:'Bearer '+session.token},data:{messages:[{role:'user',content:'今選んでいる実習教材で紹介される練習例の英語名だけを短く答えてください。'}],playback:{video_id:Number(before.resource.video_id),position_seconds:10}}});
   const status=response.status();if(status!==200)return {learner:session.index,httpStatus:status,ok:false,milliseconds:Math.round(performance.now()-began)};
   const body=await readLtiAnswer(response);const text=body.answer.segments.map(part=>part.text).join('');
   const ok=/silver\s+maple|シルバー.*メープル/i.test(text)&&body.answer.sources.some(source=>String(source.video_id)===String(before.resource.video_id));
   return {learner:session.index,httpStatus:status,ok,milliseconds:Math.round(performance.now()-began)};
  }catch(error){return {learner:session.index,ok:false,error:error.message.startsWith('LTI answer failed: ')?error.message.slice(0,100):error.name,milliseconds:Math.round(performance.now()-began)};}
  finally{if(++finished%10===0)console.log(finished+'/'+count+' simultaneous questions finished.');}
 }));
 const after=state();assert.equal(after.inventory.account_count,before.inventory.account_count);
 const elapsed=results.map(row=>row.milliseconds).sort((a,b)=>a-b);
 const artifact={quota,concurrentLearners:count,realMoodleLogins:true,realSignedLtiSessions:true,realAi:true,allQuestionsStartedTogether:true,simultaneousVideoPlaybackTested:false,results,successful:results.filter(row=>row.ok).length,failed:results.filter(row=>!row.ok).length,p50Milliseconds:elapsed[Math.floor(elapsed.length*0.5)],p95Milliseconds:elapsed[Math.min(elapsed.length-1,Math.floor(elapsed.length*0.95))],maxMilliseconds:elapsed.at(-1),additionalVideoqAccounts:after.inventory.account_count-before.inventory.account_count,answersCharged:after.usage.used_ai_answers-before.usage.used_ai_answers};
 writeFileSync(dir+'/classroom-load-'+count+'.json',JSON.stringify(artifact,null,2));
 assert.equal(artifact.failed,0,artifact.failed+' simultaneous questions did not return the expected real answer');
 console.log(count+' concurrent real learner questions passed; p95 '+artifact.p95Milliseconds+' ms.');
}finally{
 await browser.close();await client.dispose();docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','/lab/acceptance-users.php','cleanup',String(count));
}

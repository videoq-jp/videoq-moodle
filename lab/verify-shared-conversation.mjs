// Real Moodle/LTI and real answers; a disposable learner keeps real learners' history untouched.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const docker=(...args)=>execFileSync('sudo',['-n','docker',...args],{encoding:'utf8',timeout:60000});
const users=JSON.parse(docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','/lab/acceptance-users.php','create','1'));
const browser=await chromium.launch({headless:true,args:['--no-proxy-server']});
try{
 const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1440,height:1000}});const page=await context.newPage();let sent;
 page.on('request',r=>{if(r.url().endsWith('/api/lti/messages/stream'))sent=r.postDataJSON();});
 const ready=async()=>page.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false,null,{timeout:60000});
 await page.goto('https://moodle.localhost:18443/login/index.php');await page.locator('#username').fill(users[0].username);await page.locator('.toggle-sensitive-wrapper #password').waitFor({state:'attached'});await page.locator('#password').fill('Lti-lab-only-123!');await Promise.all([page.waitForURL(url=>!url.pathname.includes('/login/')),page.locator('#loginbtn').click()]);
 await page.goto('https://moodle.localhost:18443/local/videoq/view.php?lesson=sync_5');await ready();assert.equal(new URL(page.url()).searchParams.get('course'),'2');assert.equal(new URL(page.url()).searchParams.get('video'),'5');assert(!new URL(page.url()).searchParams.has('lesson'));
 const heading=await page.locator('h1').innerText();
 let chat=page.locator('#videoq-native-chat');
 await chat.locator('[data-region="send-message-txt"]').fill('今の第1章の動画で練習例につけられた英語の名前だけを答えてください。');await chat.locator('[data-action="send-message"]').click();
 await page.waitForFunction(()=>document.querySelector('#videoq-native-chat .answer [data-feedback="good"]')&&!document.querySelector('#videoq-native-controls').disabled,null,{timeout:120000});
 assert.match(await chat.locator('.answer [data-region="text-container"]').innerText(),/blue\s+lantern/i);
 let feedback=page.waitForResponse(r=>r.url().endsWith('/api/lti/feedback'));await chat.locator('[data-feedback="good"]').click();assert.equal((await feedback).status(),200);
 await page.goto('https://moodle.localhost:18443/local/videoq/view.php?lesson=sync_21');await ready();chat=page.locator('#videoq-native-chat');
 assert.equal(await page.locator('h1').innerText(),heading);assert.equal(await page.locator('#videoq-lesson').inputValue(),'sync_21');assert.equal(await chat.locator('.answer').count(),1);
 assert.match(await chat.locator('.answer [data-region="text-container"]').innerText(),/blue\s+lantern/i);
 assert.equal(await chat.locator('[data-feedback="good"]').getAttribute('aria-pressed'),'true');
 feedback=page.waitForResponse(r=>r.url().endsWith('/api/lti/feedback'));await chat.locator('[data-feedback="bad"]').click();assert.equal((await feedback).status(),200);
 await chat.locator('[data-region="send-message-txt"]').fill('先ほどの練習例と、今の第2章の動画の練習例について、英語名をそれぞれ教えてください。');await chat.locator('[data-action="send-message"]').click();
 await page.waitForFunction(()=>document.querySelectorAll('#videoq-native-chat .answer').length===2&&!document.querySelector('#videoq-native-controls').disabled,null,{timeout:120000});
 assert.equal(sent.messages.length,3);assert.match(sent.messages[1].content,/blue\s+lantern/i);
 const answer=await chat.locator('.answer [data-region="text-container"]').last().innerText();assert.match(answer,/blue\s+lantern/i);assert.match(answer,/green\s+compass/i);
 await page.goto('https://moodle.localhost:18443/local/videoq/view.php?course=2');await ready();chat=page.locator('#videoq-native-chat');assert.equal(await chat.locator('.answer').count(),2);
 await page.locator('#videoq-lesson').selectOption('sync_50');assert.equal(await chat.locator('.answer').count(),2);
 // A second tab for the same Moodle identity opens the same course conversation.
 const second=await context.newPage();await second.goto('https://moodle.localhost:18443/local/videoq/view.php?course=2&video=5');await second.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false);assert.equal(await second.locator('#videoq-native-chat .answer').count(),2);
 writeFileSync('/tmp/videoq-lti-lab/acceptance/shared-conversation.json',JSON.stringify({legacyLinksRedirectToCourse:true,videoPreselected:true,oneCourseHeading:true,historyAcrossVideoLinks:true,historyAcrossTabs:true,historyOnReload:true,feedbackRetiedToNewSession:true,modelReceivesPriorContext:true,realAnswers:2,selectorPreservesHistory:true},null,2));
 console.log('Shared course conversation survives video links, reload and another tab; real follow-up context and restored Good/Bad work.');
}finally{await browser.close();docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','/lab/acceptance-users.php','cleanup','1');}

// A stable course entry must follow live Moodle permissions, including when its initial video disappears.
import { chromium, firefox, webkit } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync,writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const dir='/tmp/videoq-lti-lab/acceptance';
const course=JSON.parse(readFileSync(dir+'/course-organization.json'));
const sources=course.entries.filter(r=>r.visible).map(r=>r.sourceCmid);
const php=code=>execFileSync('sudo',['-n','docker','exec','-i','-w','/var/www/html','videoq-lti-lab-moodle-1','php'],{input:'<?php define("CLI_SCRIPT",true);require "config.php";if($CFG->wwwroot!=="https://moodle.localhost:18443")throw new Exception("Lab only");require_once($CFG->dirroot."/course/lib.php");\\core\\session\\manager::set_user(get_admin());'+code,encoding:'utf8'});
const original=JSON.parse(php('echo json_encode(array_values($DB->get_records_list("course_modules","id",'+JSON.stringify(sources).replaceAll('[','[')+',"id","id,visible,visibleoncoursepage,availability")));'));
function synchronize(){php('foreach($DB->get_records("local_videoq_sync",["courseid"=>2,"enabled"=>1]) as $row)\\local_videoq\\synchronizer::run((int)$row->id);');}
function visibility(ids,visible){php('foreach('+JSON.stringify(ids)+' as $id)set_coursemodule_visible($id,'+visible+');');}
const engine=process.env.LTI_BROWSER||'chromium';
const browser=await ({chromium,firefox,webkit})[engine].launch({headless:true,args:engine==='chromium'?['--no-proxy-server']:[]});
async function login(username){const page=await browser.newPage({ignoreHTTPSErrors:true});await page.goto('https://moodle.localhost:18443/login/index.php');await page.locator('#username').fill(username);await page.locator('.toggle-sensitive-wrapper #password').waitFor({state:'attached'});await page.locator('#password').fill('Lti-lab-only-123!');await Promise.all([page.waitForURL(url=>!url.pathname.includes('/login/')),page.locator('#loginbtn').click()]);return page;}
async function ready(page){await page.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false);}
const checks=[];
try{
 const page=await login('learner');let session;
 page.on('response',async r=>{if(r.url().endsWith('/api/lti/session'))session=await r.json();});
 await page.goto('https://moodle.localhost:18443/local/videoq/view.php?course=2');await ready(page);
 assert.equal(await page.locator('#videoq-lesson option').count(),3);assert.equal(session.playback.videos.length,3);
 checks.push('All three synchronized videos belong to the shared course session.');
 const first=Number((await page.locator('#videoq-lesson').inputValue()).replace('sync_',''));
 const firstVideo=session.playback.videos.find(v=>v.lesson==='sync_'+first).videoId;
 visibility([first],0);
 const denied=await page.request.post('https://videoq.localhost:18443/api/lti/messages',{headers:{Origin:'https://videoq.localhost:18443',Authorization:'Bearer '+session.token},data:{messages:[{role:'user',content:'この動画について教えて'}],playback:{video_id:firstVideo,position_seconds:0}}});
 assert.equal(denied.status(),403);checks.push('An already-open session cannot ask about a newly hidden video.');
 await page.reload();await ready(page);assert.notEqual(await page.locator('#videoq-lesson').inputValue(),'sync_'+first);assert.equal(await page.locator('#videoq-lesson option').count(),2);
 checks.push('Hiding the initial video keeps the same course URL usable with another permitted video.');
 visibility(sources,0);await page.reload();await page.getByText('現在、質問できる動画教材はありません。教材の処理が完了すると利用できます。',{exact:false}).waitFor();assert.equal(await page.locator('#videoq-questions').count(),0);
 checks.push('No permitted videos produces a clear empty state without launching an unauthorized session.');
 for(const row of original)php('set_coursemodule_visible('+row.id+','+row.visible+','+row.visibleoncoursepage+');');
 synchronize();await page.reload();await ready(page);assert.equal(await page.locator('#videoq-lesson option').count(),3);
 checks.push('Restoring visibility makes the three videos available again.');
 const outsider=await login('outsider');await outsider.goto('https://moodle.localhost:18443/local/videoq/view.php?course=2');assert.equal(await outsider.locator('#videoq-questions').count(),0);assert.equal(await outsider.locator('#videoq-lesson').count(),0);
 checks.push('An unenrolled user cannot enter the course assistant or see its video catalog.');
 writeFileSync(dir+'/course-entry-'+engine+'.json',JSON.stringify({engine,checks,paidRequests:0},null,2));console.log(engine+': '+checks.length+' shared course-entry permission and lifecycle checks passed.');
}catch(error){for(const context of browser.contexts())for(const page of context.pages()){writeFileSync(dir+'/course-entry-failure.txt',await page.locator('body').innerText());await page.screenshot({path:dir+'/course-entry-failure.png'});}throw error;}finally{
 for(const row of original)php('set_coursemodule_visible('+row.id+','+row.visible+','+row.visibleoncoursepage+');');
 synchronize();await browser.close();
}

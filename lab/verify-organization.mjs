import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const dir='/tmp/videoq-lti-lab/acceptance';
const expected=JSON.parse(readFileSync(dir+'/course-organization.json'));
const browser=await chromium.launch({headless:true,args:['--no-proxy-server']});
try {
 const page=await browser.newPage({ignoreHTTPSErrors:true,viewport:{width:1440,height:1050}});
 for(let attempt=0;;attempt++){
  try{await page.goto('https://moodle.localhost:18443/login/index.php');break;}
  catch(error){if(attempt>=2||!error.message.includes('ERR_NETWORK_CHANGED'))throw error;}
 }
 await page.locator('#username').fill('learner');
 await page.locator('.toggle-sensitive-wrapper #password').waitFor({state:'attached'});
 await page.locator('#password').fill('Lti-lab-only-123!');
 await Promise.all([page.waitForURL(url=>!url.pathname.includes('/login/')),page.locator('#loginbtn').click()]);
 await page.goto('https://moodle.localhost:18443/course/view.php?id='+expected.courseId);
 const activities=await page.locator('li.activity').evaluateAll(items=>items.map(item=>({id:item.id,text:item.innerText,type:item.getAttribute('class')})));
 for(const entry of expected.entries) {
  assert.equal(activities.some(item=>item.id==='module-'+entry.entryCmid),entry.entryCmid===expected.assistantCmid);
  assert.equal(activities.some(item=>item.id==='module-'+entry.sourceCmid),entry.visible);
  assert(!activities.some(item=>item.id==='module-'+entry.ltiCmid));
 }
 assert.equal(activities.filter(item=>item.type.includes('modtype_url')).length,1);
 assert.equal(activities.filter(item=>item.type.includes('modtype_resource')).length,3);
 const general=page.locator('[data-sectionid="0"]');
 assert.equal(await general.locator('#module-'+expected.assistantCmid).count(),1);
 assert((await general.locator('#module-'+expected.assistantCmid).innerText()).includes('AIアシスタント（講座全体に質問）'));
 assert.equal(activities.filter(item=>item.type.includes('modtype_lti')).length,0);
 assert(!(await page.locator('body').innerText()).includes('補足教材（準備中）'));
 await page.screenshot({path:dir+'/course-organized.png',fullPage:true});
 // The real automatic-sync lesson must still launch with its off-list source and LTI.
 await page.goto('https://moodle.localhost:18443/local/videoq/view.php?lesson=sync_30');
 await page.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false);
 assert.equal(await page.locator('#videoq-player').count(),1);
 writeFileSync(dir+'/organization-check.json',JSON.stringify({courseAssistantEntries:1,chapterVideos:3,duplicateLtiActivities:0,unpublishedSectionHidden:true,automaticLessonLaunch:true,activities},null,2));
 console.log('One course-wide assistant in General, three chapter videos, no LTI duplicates, hidden content protected and automatic lesson launches.');
} finally {await browser.close();}

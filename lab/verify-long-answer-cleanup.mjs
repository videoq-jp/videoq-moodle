// Verify the real long-video answer, then delete ONLY that temporary source and prove physical cleanup.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync,writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { readLtiAnswer } from './read-lti-answer.mjs';
const root='/tmp/videoq-lti-lab/acceptance';const upload=JSON.parse(readFileSync(root+'/long-upload.json'));const processing=JSON.parse(readFileSync(root+'/long-processing.json'));
assert.equal(upload.cmid,processing.sourceCmid);
const docker=(...args)=>execFileSync('sudo',['-n','docker',...args],{encoding:'utf8',timeout:120000});
const query=sql=>JSON.parse(docker('exec','videoq-lti-lab-api-1','node','--input-type=module','-e',`import {labDatabase} from '/fixtures/lab-database.mjs';const db=await labDatabase();console.log(JSON.stringify((await db.query(${JSON.stringify(sql)})).rows));await db.end();`));
const existing=query('SELECT file FROM videos WHERE id='+Number(processing.videoId));assert.equal(existing.length,1);const file=existing[0].file;
const browser=await chromium.launch({headless:true,args:['--no-proxy-server']});
try{
 const page=await browser.newPage({ignoreHTTPSErrors:true,viewport:{width:1440,height:1000}});
 await page.goto('https://moodle.localhost:18443/login/index.php');await page.locator('#username').fill('learner');await page.locator('.toggle-sensitive-wrapper #password').waitFor({state:'attached'});await page.locator('#password').fill('Lti-lab-only-123!');await Promise.all([page.waitForURL(url=>!url.pathname.includes('/login/')),page.locator('#loginbtn').click()]);
 await page.goto('https://moodle.localhost:18443/local/videoq/view.php?course=2');await page.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false);
 assert.equal(await page.locator('#videoq-lesson option').count(),4);
 await page.locator('#videoq-lesson').selectOption('sync_'+upload.cmid);
 const chat=page.locator('#videoq-native-chat');const responsePromise=page.waitForResponse(r=>r.url().endsWith('/api/lti/messages/stream'),{timeout:120000});
 await chat.locator('[data-region="send-message-txt"]').fill('今選んでいる30分の講義の最後、29分30秒以降に紹介された final checkpoint の英語名だけを、教材の根拠とともに教えてください。');await chat.locator('[data-action="send-message"]').click();
 const response=await responsePromise;assert.equal(response.status(),200);const result=await readLtiAnswer(response);await page.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false,null,{timeout:120000});
 const answer=await chat.locator('.answer [data-region="text-container"]').innerText();assert.match(answer,/copper\s+harbo[u]?r/i);
 const source=result.answer.sources.find(s=>String(s.video_id)===processing.videoId);assert(source);
 await page.screenshot({path:root+'/long-answer.png',fullPage:true});
 // This exact, specially named source was created by this acceptance run.
 docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','-r',`define('CLI_SCRIPT',true);require 'config.php';require_once($CFG->dirroot.'/course/lib.php');$cm=get_coursemodule_from_id('resource',${upload.cmid},2,false,MUST_EXIST);if($CFG->wwwroot!=='https://moodle.localhost:18443'||$cm->name!=='検証用：30分の講義（検証後削除）')throw new Exception('Not the disposable source');\\core\\session\\manager::set_user(get_admin());course_delete_module($cm->id,false);`);
 docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','admin/cli/scheduled_task.php',String.raw`--execute=\local_videoq\task\synchronize`);
 docker('exec','videoq-lti-lab-api-1','node','dist/node.mjs','maintenance');
 assert.equal(query('SELECT id FROM videos WHERE id='+Number(processing.videoId)).length,0);
 assert.equal(query('SELECT video_id FROM scene_embeddings WHERE video_id='+Number(processing.videoId)).length,0);
 const object=JSON.parse(docker('exec','videoq-lti-lab-worker-1','python','/lab/check-sync-object.py',file));assert.equal(object.exists,false);
 await page.reload();await page.waitForFunction(()=>document.querySelector('#videoq-native-controls')?.disabled===false);assert.equal(await page.locator('#videoq-lesson option').count(),3);
 assert.equal(await page.locator('#videoq-lesson option[value="sync_'+upload.cmid+'"]').count(),0);
 writeFileSync(root+'/long-answer-cleanup.json',JSON.stringify({sourceCmid:upload.cmid,videoId:processing.videoId,realAnswer:true,answer,citation:source,addedVideoAutomaticallyAvailable:true,deletedVideoAutomaticallyExcluded:true,videoRecordRemoved:true,vectorsRemoved:true,mediaObjectRemoved:true,sharedCourseEntrySurvives:true},null,2));
 console.log('Real answer cites the thirty-minute lecture ending; deleting its Moodle source removes media/vectors and leaves the common entry working.');
}finally{await browser.close();}

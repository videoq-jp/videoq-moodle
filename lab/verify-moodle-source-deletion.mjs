import { chromium } from 'playwright';
// Source deletion must be propagated by periodic sync/cleanup, while peer videos remain intact.
import {execFileSync} from 'node:child_process';
import {setTimeout} from 'node:timers/promises';
import {writeFileSync} from 'node:fs';
import assert from 'node:assert/strict';
const docker=(...args)=>execFileSync('sudo',['-n','docker',...args],{encoding:'utf8',timeout:60000}).trim();
const action=name=>JSON.parse(docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','/lab/managed-repair-actions.php',name));
const query=sql=>JSON.parse(docker('exec','videoq-lti-lab-api-1','node','--input-type=module','-e',`import{labDatabase}from'/fixtures/lab-database.mjs';const d=await labDatabase();console.log(JSON.stringify((await d.query(${JSON.stringify(sql)})).rows));await d.end();`));
const peers=()=>query("SELECT external_id,video_id,course_id FROM lms_resources WHERE installation_id='moodle-sync-lab' AND enabled AND external_id IN('5','21','50','82') ORDER BY external_id");
const before=peers();let local,original,browser,page;
try{
 local=action('setup');
 for(let attempt=0;attempt<90;attempt++){
  original=query("SELECT v.id,v.file,v.status,(SELECT count(*)::int FROM scene_embeddings e WHERE e.video_id=v.id) AS scenes FROM lms_resources r JOIN videos v ON v.id=r.video_id WHERE r.installation_id='moodle-sync-lab' AND r.external_id='"+local.cmid+"'")[0];
  if(original?.status==='completed'&&original.scenes>0)break;
  await setTimeout(2000);
 }
 assert(original?.status==='completed'&&original.scenes>0);
 if(process.env.LTI_VERIFY_LIBRARY==='1'){
  browser=await chromium.launch({headless:true,args:['--no-proxy-server']});page=await browser.newPage({ignoreHTTPSErrors:true,locale:'ja-JP'});
  await page.goto('https://videoq.localhost:18443/login');await page.locator('#username').fill('videoq_lab');await page.locator('#password').fill('Lti-lab-only-123!');const login=page.waitForResponse(r=>r.url().includes('/api/auth/sign-in/'));await page.locator('form button[type="submit"]').click();assert.equal((await login).status(),200);await page.waitForURL(url=>!url.pathname.endsWith('/login'));
  await page.goto('https://videoq.localhost:18443/videos');await page.locator('a[href$="/videos/'+original.id+'"]').first().waitFor();
 }
 const removed=action('source');assert.equal(removed.sourceExists,false);const started=Date.now();let clean=false;
 for(let attempt=0;attempt<60;attempt++){
  const resource=query("SELECT enabled,video_id FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id='"+local.cmid+"'")[0];
  if(resource&&!resource.enabled&&resource.video_id===null){
   const object=JSON.parse(docker('exec','videoq-lti-lab-worker-1','python','/lab/check-sync-object.py',original.file));
   if(!object.exists){clean=true;break;}
  }
  await setTimeout(2000);
 }
 assert(clean,'Periodic services did not complete physical deletion');
 assert.equal(query('SELECT id FROM videos WHERE id='+Number(original.id)).length,0);assert.equal(query('SELECT video_id FROM scene_embeddings WHERE video_id='+Number(original.id)).length,0);
 assert.deepEqual(peers(),before);
 if(page)await page.locator('a[href$="/videos/'+original.id+'"]').first().waitFor({state:'detached',timeout:90000});
 writeFileSync('/tmp/videoq-lti-lab/acceptance/moodle-source-deletion.json',JSON.stringify({sourceCmid:local.cmid,deletedVideoId:original.id,sourceDeletedViaMoodleCore:true,libraryRemovedWithoutReload:!!page,explicitSyncOrMaintenanceAfterDeletion:false,videoRemoved:true,transcriptRemoved:true,vectorsRemoved:true,mediaObjectRemoved:true,otherSynchronizedVideosPreserved:before,deletionMilliseconds:Date.now()-started},null,2));
 console.log('Moodle source deletion automatically removes VideoQ record, transcript, vectors and media; all live peer videos preserved.');
}finally{if(browser)await browser.close();if(local){action('cleanup');query("DELETE FROM video_courses c WHERE c.user_id='lti-lab-owner' AND c.name='VideoQ internal repair acceptance' AND c.id IN (SELECT course_id FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id='"+local.cmid+"') AND NOT EXISTS(SELECT 1 FROM video_course_members m WHERE m.course_id=c.id) AND NOT EXISTS(SELECT 1 FROM chat_logs l WHERE l.course_id=c.id) RETURNING id");}}

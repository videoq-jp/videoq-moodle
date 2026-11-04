// Remove only the original manually seeded duplicates, using normal authenticated video deletion.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const query=sql=>JSON.parse(execFileSync('sudo',['-n','docker','exec','videoq-lti-lab-api-1','node','--input-type=module','-e',`import{labDatabase}from'/fixtures/lab-database.mjs';const d=await labDatabase();console.log(JSON.stringify((await d.query(${JSON.stringify(sql)})).rows));await d.end();`],{encoding:'utf8'}));
const rows=query("SELECT id,file FROM videos WHERE user_id='lti-lab-owner' AND id IN(1,11,12) ORDER BY id");
const expected=new Map([[1,'lab/lesson.mp4'],[11,'lab/course-review.mp4'],[12,'lab/course-hidden.mp4']]);
for(const row of rows)assert.equal(row.file,expected.get(Number(row.id)));
const active=query("SELECT video_id FROM lms_resources WHERE enabled AND video_id IS NOT NULL").map(row=>Number(row.video_id));
for(const row of rows)assert(!active.includes(Number(row.id)),'Never remove a current synchronized video');
const browser=await chromium.launch({headless:true,args:['--no-proxy-server']});
try{
 const page=await browser.newPage({ignoreHTTPSErrors:true});await page.goto('https://videoq.localhost:18443/login');await page.locator('#username').fill('videoq_lab');await page.locator('#password').fill('Lti-lab-only-123!');const login=page.waitForResponse(r=>r.url().includes('/api/auth/sign-in/'));await page.locator('form button[type="submit"]').click();assert.equal((await login).status(),200);
 for(const row of rows){const response=await page.request.post('https://videoq.localhost:18443/api/trpc/videos.delete',{headers:{Origin:'https://videoq.localhost:18443'},data:{id:Number(row.id)}});assert.equal(response.status(),200);}
 assert.equal(query("SELECT id FROM videos WHERE id IN(1,11,12)").length,0);
 assert.equal(query("SELECT video_id FROM scene_embeddings WHERE video_id IN(1,11,12)").length,0);
 assert.deepEqual(query("SELECT video_id FROM lms_resources WHERE enabled AND video_id IS NOT NULL").map(row=>Number(row.video_id)).sort((a,b)=>a-b),active.sort((a,b)=>a-b));
 writeFileSync('/tmp/videoq-lti-lab/legacy-fixtures-retired.json',JSON.stringify({deletedLegacyVideoIds:rows.map(row=>Number(row.id)),activeSynchronizedVideosPreserved:active,normalVideoDeletionApi:true},null,2));
 console.log('Obsolete manual fixtures removed; all active synchronized videos preserved.');
}finally{await browser.close();}

// Concurrent authenticated Moodle Range reads; independent from AI and video decoding.
import { request } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync,writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
const dir='/tmp/videoq-lti-lab/acceptance',count=100,bytes=1024*1024;
const upload=JSON.parse(readFileSync(dir+'/moodle-upload.json'));
const expected=createHash('sha256').update(readFileSync(dir+'/spoken-lesson-100m.mp4').subarray(0,bytes)).digest('hex');
const docker=(...args)=>execFileSync('sudo',['-n','docker',...args],{encoding:'utf8',timeout:120000});
const users=JSON.parse(docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','/lab/acceptance-users.php','create',String(count)));
const clients=[];
try{
 for(let offset=0;offset<count;offset+=5){
  clients.push(...await Promise.all(users.slice(offset,offset+5).map(async user=>{
   const client=await request.newContext({ignoreHTTPSErrors:true,timeout:60000});
   try{
    const login=await client.get('https://moodle.localhost:18443/login/index.php');const html=await login.text();
    const input=html.match(/<input\b[^>]*\bname=["']logintoken["'][^>]*>/i)?.[0];
    const token=input?.match(/\bvalue=["']([A-Za-z0-9]+)["']/)?.[1];assert(token,'Moodle login token missing');
    const response=await client.post('https://moodle.localhost:18443/login/index.php',{form:{username:user.username,password:'Lti-lab-only-123!',logintoken:token}});
    assert.notEqual(new URL(response.url()).pathname,'/login/index.php','The real Moodle login must succeed');return client;
   }catch(error){await client.dispose();throw error;}
  })));
  if(clients.length%20===0)console.log(clients.length+' Moodle media sessions authenticated.');
 }
 const started=performance.now();
 const results=await Promise.all(clients.map(async(client,index)=>{
  const response=await client.get('https://moodle.localhost:18443/mod/resource/view.php?id='+upload.cmid+'&redirect=1',{headers:{Range:'bytes=0-'+(bytes-1)}});
  assert.equal(response.status(),206);assert.equal(response.headers()['content-range'],'bytes 0-'+(bytes-1)+'/'+upload.bytes);
  const data=await response.body();assert.equal(data.length,bytes);assert.equal(createHash('sha256').update(data).digest('hex'),expected);
  return {learner:index+1,status:response.status(),bytes:data.length,milliseconds:Math.round(performance.now()-started)};
 }));
 const times=results.map(row=>row.milliseconds).sort((a,b)=>a-b);
 writeFileSync(dir+'/classroom-media-100.json',JSON.stringify({learners:count,realMoodleLogins:true,concurrentAuthenticatedRangeReads:true,bytesPerLearner:bytes,totalBytes:count*bytes,matchingChecksums:true,allPartialContent:true,continuousVideoPlaybackTested:false,productionInternetBandwidthTested:false,p95Milliseconds:times[95],maxMilliseconds:times.at(-1),results},null,2));
 console.log('100 authenticated concurrent Moodle media Range reads passed with matching bytes; p95 '+times[95]+' ms.');
}finally{await Promise.all(clients.map(client=>client.dispose()));docker('exec','-w','/var/www/html','videoq-lti-lab-moodle-1','php','/lab/acceptance-users.php','cleanup',String(count));}

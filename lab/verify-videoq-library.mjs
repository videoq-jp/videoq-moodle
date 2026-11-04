// Exercise library card navigation and actual decoded playback, rather than only the course viewer.
import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true,args:['--no-proxy-server']});
try{
 const page=await browser.newPage({ignoreHTTPSErrors:true,locale:'ja-JP',viewport:{width:1440,height:1050}});
 await page.goto('https://videoq.localhost:18443/login');await page.locator('#username').fill('videoq_lab');await page.locator('#password').fill('Lti-lab-only-123!');
 const loggedIn=page.waitForResponse(r=>r.url().includes('/api/auth/sign-in/'));await page.locator('form button[type="submit"]').click();assert.equal((await loggedIn).status(),200);await page.waitForURL(url=>!url.pathname.endsWith('/login'));
 await page.goto('https://videoq.localhost:18443/videos');
 await page.locator('a[href*="/videos/"]').filter({has:page.locator('video')}).first().waitFor({timeout:30000});
 const links=await page.locator('a[href*="/videos/"]').evaluateAll(items=>[...new Set(items.map(item=>item.getAttribute('href')))].filter(path=>/\/videos\/\d+$/.test(path)));
 assert(links.length>0);assert(!(await page.locator('body').innerText()).includes('An internal server error occurred.'));
 await page.screenshot({path:'/tmp/videoq-lti-lab/videoq-library-screen.png',fullPage:true});const results=[];
 for(const link of links){
  if(!new URL(page.url()).pathname.endsWith('/videos'))await page.goto('https://videoq.localhost:18443/videos');
  await page.locator('a[href="'+link+'"]').first().click();
  await page.waitForFunction(()=>{const h=document.querySelector('h1');return h&&h.textContent!=='ライブラリ'&&document.querySelectorAll('video').length===1;});
  await page.waitForFunction(()=>document.querySelector('video')?.readyState>=2,null,{timeout:30000});
  const video=page.locator('video').first();await video.evaluate(async element=>{element.muted=true;await element.play();});
  await page.waitForFunction(()=>document.querySelector('video')?.currentTime>0.25);
  const playback=await video.evaluate(element=>{element.pause();return {width:element.videoWidth,height:element.videoHeight,duration:element.duration,error:element.error?.code||null};});
  assert(playback.width>0&&playback.error===null);results.push({id:Number(link.split('/').at(-1)),...playback});console.log('Library video '+results.at(-1).id+' plays.');
 }
 writeFileSync('/tmp/videoq-lti-lab/videoq-library-verification.json',JSON.stringify({libraryLoads:true,actualCardNavigation:true,allVideosPlayable:true,visibleVideos:links.length,results},null,2));
}catch(error){for(const context of browser.contexts())for(const page of context.pages()){await page.screenshot({path:'/tmp/videoq-lti-lab/library-failure.png',fullPage:true});console.error((await page.locator('body').innerText()).slice(-1200));}throw error;}
finally{await browser.close();}

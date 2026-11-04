// Verify the original frontend against the same school account and synced Moodle data.
import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true,args:['--no-proxy-server']});
try{
 const page=await browser.newPage({ignoreHTTPSErrors:true,locale:'ja-JP',viewport:{width:1600,height:1050}});
 page.on('response',r=>{if(r.status()>=400)console.log('HTTP',r.status(),new URL(r.url()).pathname);});
 await page.goto('https://videoq.localhost:18443/ja/login');
 await page.locator('#username').fill('videoq_lab');await page.locator('#password').fill('Lti-lab-only-123!');
 const responsePromise=page.waitForResponse(r=>r.url().includes('/api/auth/sign-in/'));
 await page.locator('form button[type="submit"]').click();const response=await responsePromise;assert.equal(response.status(),200);
 await page.waitForURL(url=>!url.pathname.endsWith('/login'));
 await page.goto('https://videoq.localhost:18443/ja/videos/courses/7');
 await page.getByText('01-01-画像の標本化・量子化',{exact:false}).first().waitFor({timeout:30000});
 await page.waitForFunction(()=>document.querySelector('video')?.readyState>=2,null,{timeout:60000});
 const video=page.locator('video');await video.evaluate(async element=>{element.muted=true;await element.play();});
 await page.waitForFunction(()=>document.querySelector('video').currentTime>0.3);
 const playback=await video.evaluate(element=>{element.pause();return {width:element.videoWidth,height:element.videoHeight,duration:element.duration,errorCode:element.error?.code||null};});assert(playback.width>0&&playback.errorCode===null);
 await page.screenshot({path:'/tmp/videoq-lti-lab/videoq-course-screen.png',fullPage:true});
 writeFileSync('/tmp/videoq-lti-lab/videoq-web-view.json',JSON.stringify({url:page.url(),ownerLoginVerified:true,courseVisible:true,playback},null,2));
 console.log('Original VideoQ course screen loaded with the real synced Moodle videos.');
}catch(error){for(const c of browser.contexts())for(const page of c.pages()){await page.screenshot({path:'/tmp/videoq-lti-lab/videoq-web-failure.png',fullPage:true});console.error((await page.locator('body').innerText()).slice(-1600));}throw error;}
finally{await browser.close();}

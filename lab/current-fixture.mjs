// Resolve acceptance IDs from the current synchronized source, preserving the original fixture mode.
import { existsSync, readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
export function currentFixture(dir='/tmp/videoq-lti-lab') {
 const videoq=JSON.parse(readFileSync(dir+'/videoq-fixture.json'));
 const moodle=JSON.parse(readFileSync(dir+'/moodle-fixture.json'));
 const extra=existsSync(dir+'/course-videos.json')?JSON.parse(readFileSync(dir+'/course-videos.json')).videos:[];
 const migrated=existsSync(dir+'/acceptance/converged-sources.json');
 let firstLesson='lms-videoq', standaloneUrl;
 if(migrated){
  const sources=JSON.parse(readFileSync(dir+'/acceptance/converged-sources.json'));
  const state=cmid=>JSON.parse(execFileSync('sudo',['-n','docker','exec','videoq-lti-lab-api-1','node','/fixtures/sync-lab-state.mjs',String(cmid)],{encoding:'utf8'}));
  const first=sources.find(item=>item.alias==='lms-videoq');const firstState=state(first.sourceCmid);
  if(firstState.resource?.status!=='completed')throw new Error('The synchronized first source is not ready');
  videoq.videoId=Number(firstState.resource.video_id);videoq.courseId=Number(firstState.resource.course_id);firstLesson=first.lesson;
  standaloneUrl='https://moodle.localhost:18443/mod/lti/launch.php?id='+first.ltiCmid;
  for(const source of sources){
   const item=extra.find(item=>item.lesson===source.alias);
   if(item){const current=state(source.sourceCmid);item.videoId=Number(current.resource.video_id);item.lesson=source.lesson;item.ltiCmid=source.ltiCmid;}
  }
 }
 return {videoq,moodle,extra,firstLesson,standaloneUrl,migrated};
}

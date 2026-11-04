// The original demo metadata also needs actual objects before exposing the full VideoQ library.
import { execFileSync } from 'node:child_process';
import { existsSync,readFileSync } from 'node:fs';
import { join } from 'node:path';
const root='/tmp/videoq-lti-lab';
if(existsSync(join(root,'legacy-fixtures-retired.json'))){console.log('Legacy fixtures were retired; deleted demo media will not be recreated.');process.exit(0);}
const fixture=JSON.parse(readFileSync(join(root,'videoq-fixture.json')));
if(fixture.owner!=='lti-lab-owner'||!Number.isSafeInteger(fixture.videoId))throw new Error('Unexpected lab owner/video');
const docker=(args,input)=>execFileSync('sudo',['-n','docker',...args],{encoding:'utf8',input,timeout:60000});
const validate=`import{labDatabase}from'/fixtures/lab-database.mjs';const d=await labDatabase();const row=(await d.query('SELECT file,source_type FROM videos WHERE id=$1 AND user_id=$2',[${fixture.videoId},'lti-lab-owner'])).rows[0];if(!row||!['','lab/lesson.mp4'].includes(row.file)||!['file','uploaded'].includes(row.source_type))throw new Error('Not the original demo fixture');await d.end();`;
docker(['exec','videoq-lti-lab-api-1','node','--input-type=module','-e',validate]);
const files=['lesson','course-review','course-hidden'];
for(const name of files)if(!existsSync(join(root,name+'.mp4')))throw new Error('Generate original demo media first: '+name);
for(const name of files)docker(['cp',join(root,name+'.mp4'),'videoq-lti-lab-worker-1:/tmp/videoq-web-'+name+'.mp4']);
docker(['exec','-i','videoq-lti-lab-worker-1','python','-'],String.raw`import os,boto3
if os.environ.get('DATABASE_URL')!='postgresql://postgres:lti-lab-only@db:5432/videoq':raise RuntimeError('Lab only')
s=boto3.client('s3',endpoint_url=os.environ['R2_S3_ENDPOINT'],region_name='garage',aws_access_key_id=os.environ['R2_ACCESS_KEY_ID'],aws_secret_access_key=os.environ['R2_SECRET_ACCESS_KEY'])
for name in ['lesson','course-review','course-hidden']:
    path='/tmp/videoq-web-'+name+'.mp4'
    s.upload_file(path,'lti-sync','media/lab/'+name+'.mp4',ExtraArgs={'ContentType':'video/mp4'})
`);
docker(['exec','--user','0','videoq-lti-lab-worker-1','rm','-f',...files.map(name=>'/tmp/videoq-web-'+name+'.mp4')]);
docker(['exec','videoq-lti-lab-api-1','node','--input-type=module','-e',`import{labDatabase}from'/fixtures/lab-database.mjs';const d=await labDatabase();await d.query("UPDATE videos SET source_type='uploaded',file='lab/lesson.mp4' WHERE id=$1 AND user_id='lti-lab-owner' AND file IN ('','lab/lesson.mp4')",[${fixture.videoId}]);await d.end();`]);
console.log('Original demo library metadata and media are ready for the full VideoQ frontend.');

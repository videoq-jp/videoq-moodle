// Logical backup + isolated restore; never changes the live databases.
import pg from 'pg';
import { execFileSync } from 'node:child_process';
import { mkdirSync, openSync, closeSync, statSync, writeFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import assert from 'node:assert/strict';
const dir='/tmp/videoq-lti-lab/acceptance/backups';mkdirSync(dir,{recursive:true,mode:0o700});
const ip=execFileSync('sudo',['-n','docker','inspect','-f','{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}','videoq-lti-lab-db-1'],{encoding:'utf8'}).trim();
assert.match(ip,/^\d+\.\d+\.\d+\.\d+$/);
const client=db=>new pg.Client({connectionString:'postgresql://postgres:lti-lab-only@'+ip+':5432/'+db});
const admin=client('postgres');await admin.connect();const created=[];const results=[];
try{
 for(const database of ['videoq','moodle']){
  const source=client(database);await source.connect();
  const tables=database==='videoq'?['users','videos','video_courses','video_course_members','scene_embeddings','lms_resources','lms_courses','chat_logs']:['mdl_course','mdl_course_modules','mdl_files','mdl_local_videoq_sync'];
  const counts=async db=>{const values={};for(const table of tables)values[table]=Number((await db.query('SELECT count(*) AS n FROM '+table)).rows[0].n);return values;};
  await source.query('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
  const snapshot=(await source.query('SELECT pg_export_snapshot() AS id')).rows[0].id;
  const expected=await counts(source);
  const file=dir+'/'+database+'.dump';const fd=openSync(file,'w',0o600);
  try{execFileSync('sudo',['-n','docker','exec','videoq-lti-lab-db-1','pg_dump','-U','postgres','-d',database,'-Fc','--snapshot='+snapshot],{stdio:['ignore',fd,'pipe'],timeout:60000});}finally{closeSync(fd);await source.query('COMMIT');await source.end();}
  const restored='videoq_restore_'+randomUUID().replaceAll('-','');await admin.query('CREATE DATABASE "'+restored+'"');created.push(restored);
  const input=openSync(file,'r');try{execFileSync('sudo',['-n','docker','exec','-i','videoq-lti-lab-db-1','pg_restore','-U','postgres','--no-owner','--no-privileges','-d',restored],{stdio:[input,'ignore','pipe'],timeout:60000});}finally{closeSync(input);}
  const target=client(restored);await target.connect();
  const actual=await counts(target);assert.deepEqual(actual,expected);
  assert.equal(Number((await target.query("SELECT count(*) AS n FROM pg_constraint WHERE contype='f' AND NOT convalidated")).rows[0].n),0);
  await target.end();results.push({database,bytes:statSync(file).size,counts:actual,isolatedRestore:true,foreignKeysValidated:true});
  console.log(database+': consistent-snapshot backup restored and row counts/FKs match.');
 }
 writeFileSync('/tmp/videoq-lti-lab/acceptance/database-backup.json',JSON.stringify({snapshotsConsistent:true,privateFiles:true,results,temporaryDatabasesRemoved:true},null,2));
}finally{for(const name of created){assert.match(name,/^videoq_restore_[a-f0-9]{32}$/);await admin.query('DROP DATABASE "'+name+'" WITH (FORCE)');}await admin.end();}

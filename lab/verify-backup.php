<?php
declare(strict_types=1);
require __DIR__.'/support/runtime.php';
use function VideoQLab\{guard,data,connect,rows,ensure,docker,report};
guard();$directory=data().'/acceptance/backups';if(!is_dir($directory))mkdir($directory,0700,true);
$tables=['videoq'=>['users','videos','video_courses','video_course_members','scene_embeddings','lms_resources','lms_courses','chat_logs'],
    'moodle'=>['mdl_course','mdl_course_modules','mdl_files','mdl_local_videoq_sync']];
$count=static function($connection,$names){$result=[];foreach($names as $name)$result[$name]=rows('SELECT count(*) AS n FROM '.pg_escape_identifier($connection,$name),[],connection:$connection)[0]['n'];return $result;};
$admin=connect('postgres');$results=[];$db=getenv('LAB_DB_CONTAINER')?:'videoq-lti-lab-db-1';
try{foreach($tables as $database=>$names){
    $source=connect($database);$file=$directory.'/'.$database.'.dump';$restored='videoq_restore_'.bin2hex(random_bytes(16));
    try{
        rows('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY',[],connection:$source);$snapshot=rows('SELECT pg_export_snapshot() AS id',[],connection:$source)[0]['id'];$expected=$count($source,$names);
        $mask=umask(0077);$output=fopen($file,'wb');umask($mask);chmod($file,0600);
        try{docker(['exec',$db,'pg_dump','-U','postgres','-d',$database,'-Fc','--snapshot='.$snapshot],output:$output);}finally{fclose($output);}
        rows('COMMIT',[],connection:$source);
    }finally{pg_close($source);}
    rows('CREATE DATABASE '.pg_escape_identifier($admin,$restored),[],connection:$admin);
    try{
        // Feed the dump as a file descriptor, keeping large snapshots out of memory.
        $input=fopen($file,'rb');$process=proc_open(['sudo','-n','docker','exec','-i',$db,'pg_restore','-U','postgres','--no-owner','--no-privileges','-d',$restored],[0=>$input,1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
        ensure(proc_close($process)===0,'Scratch restore failed');fclose($input);$check=connect($restored);
        try{ensure($count($check,$names)===$expected,'Restore row count mismatch');ensure(rows("SELECT count(*) AS n FROM pg_constraint WHERE contype='f' AND NOT convalidated",[],connection:$check)[0]['n']===0,'Invalid foreign keys');}finally{pg_close($check);}
        $results[]=['database'=>$database,'bytes'=>filesize($file),'counts'=>$expected,'isolatedRestore'=>true];
    }finally{rows('DROP DATABASE '.pg_escape_identifier($admin,$restored).' WITH (FORCE)',[],connection:$admin);}
}}finally{pg_close($admin);}
report('php-database-backup',['snapshotsConsistent'=>true,'privateFiles'=>true,'results'=>$results,'temporaryDatabasesRemoved'=>true]);

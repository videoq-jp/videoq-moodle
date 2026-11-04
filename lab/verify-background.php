<?php
declare(strict_types=1);
require __DIR__.'/support/browser.php';require __DIR__.'/support/s3.php';
use VideoQLab\{Browser,Storage};
use function VideoQLab\{guard,data,ensure,rows,docker,container,fixture,wait,report,videoq,command};
function course_switch(int $videoid,int $cmid):void {
    $js=str_replace(['VIDEOQ_ORIGIN','VIDEO_ID'],[json_encode(videoq()),(string)$videoid],file_get_contents(__DIR__.'/support/chat-fixture.js'));$b=new Browser($js);
    try {
        $b->login();$b->course();$catalog=$b->js("return JSON.parse(document.querySelector('.local-videoq-workspace').dataset.videoqLessons)");
        ensure(count($catalog)>=2,'Two course materials required');$target=$original=null;
        foreach($catalog as $item){if($item['lesson']==='sync_'.$cmid)$target=$item;else $original=$item;}
        ensure((bool)$target&&(bool)$original,'Both course materials required');
        $select=static function($item)use($b){$b->js("const el=document.querySelector('#videoq-lesson');el.value=arguments[0];el.dispatchEvent(new Event('change',{bubbles:true}))",[$item['lesson']]);$b->wait("return document.querySelector('video').currentSrc===arguments[0]",30,[$item['url']]);};
        $select($original);$time=$b->tool(fn($f)=>$f->js('return performance.timeOrigin'));
        $b->js("document.querySelectorAll('.answer').forEach(el=>el.dataset.existing='true')");$b->ask('別の動画の場面を確認します');
        $b->wait("return document.querySelector('.answer:not([data-existing])')!==null");$b->tool(fn($f)=>$f->js('window.finishStream()'));$b->ready();
        $b->click('.answer:not([data-existing]) button.citation');$b->wait("return document.querySelector('video').currentSrc===arguments[0]&&Math.abs(document.querySelector('video').currentTime-8)<2",30,[$target['url']]);
        ensure($b->value('#videoq-lesson')===$target['lesson'],'Citation did not switch video');$select($original);
        ensure($b->tool(fn($f)=>$f->js('return performance.timeOrigin'))===$time,'Tool reloaded');
        ensure($b->js("return document.querySelectorAll('.answer:not([data-existing])').length")===1,'Conversation lost');$b->js("document.querySelector('video').pause()");
        report('php-course-switch',['passed'=>true,'videoCount'=>count($catalog),'citationChangesVideoAndPosition'=>true,'conversationPreserved'=>true,'toolFrameNotReloaded'=>true]);
    }finally{$b->close();}
}
guard();\VideoQLab\ready();$media=$argv[1]??data().'/acceptance/spoken-lesson.mp4';ensure(is_file($media),'Provide an original spoken MP4');
$before=rows('SELECT id,file,status FROM videos ORDER BY id');$source=$video=null;$storage=new Storage();$files=[];
$replacement=data().'/acceptance/replacement-'.bin2hex(random_bytes(8)).'.mp4';
$resource=static function()use(&$source){return rows("SELECT r.video_id,r.course_id,v.status,v.file,length(v.transcript) AS transcript_chars,(SELECT count(*) FROM scene_embeddings e WHERE e.video_id=r.video_id) AS scenes FROM lms_resources r LEFT JOIN videos v ON v.id=r.video_id WHERE r.installation_id='moodle-sync-lab' AND r.external_id=$1",[(string)$source['cmid']])[0]??null;};
docker(['cp',$media,container().':/tmp/videoq-materials-acceptance.mp4']);
try {
    $source=fixture('live-material-fixture.php','create');
    $video=wait(static function()use($resource){$current=$resource();ensure(!$current||$current['status']!=='error','External processing failed');return $current&&$current['status']==='completed'&&$current['transcript_chars']&&$current['scenes']&&fixture('live-material-fixture.php','status')['status']==='completed'?$current:null;},420,2000);
    ensure($video['course_id']===rows("SELECT course_id FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id='82'")[0]['course_id'],'Wrong course membership');
    ensure($storage->exists($video['file']),'Exported media missing');$files[]=$video['file'];course_switch($video['video_id'],$source['cmid']);
    $original=$video;
    command(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',$media,'-map','0','-c','copy','-metadata','comment=VideoQ replacement acceptance '.bin2hex(random_bytes(8)),$replacement]);
    chmod($replacement,0600);ensure(hash_file('sha256',$replacement)!==hash_file('sha256',$media),'Replacement must have different bytes');
    docker(['cp',$replacement,container().':/tmp/videoq-materials-replacement.mp4']);fixture('live-material-fixture.php','replace');
    $video=wait(static function()use($resource,$original){$current=$resource();ensure(!$current||$current['status']!=='error','Replacement processing failed');return $current&&$current['video_id']!==$original['video_id']&&$current['status']==='completed'&&$current['transcript_chars']&&$current['scenes']&&fixture('live-material-fixture.php','status')['status']==='completed'?$current:null;},420,2000);
    ensure($video['course_id']===$original['course_id'],'Replacement changed course identity');ensure($storage->exists($video['file']),'Replacement media missing');$files[]=$video['file'];
    echo "Normal Moodle replacement event produced a new indexed video in the same course.\n";
}finally{
    if($source){fixture('live-material-fixture.php','delete');wait(static function()use($resource){$current=$resource();return (!$current||$current['video_id']===null)&&fixture('live-material-fixture.php','status')['status']==='disabled';},240,2000);
        fixture('live-material-fixture.php','cleanup');rows("DELETE FROM lms_resources WHERE installation_id='moodle-sync-lab' AND external_id=$1 AND video_id IS NULL",[(string)$source['cmid']]);
        docker(['exec',container(),'php','-r',"foreach(['/tmp/videoq-materials-acceptance.mp4','/tmp/videoq-materials-replacement.mp4'] as \$file)if(is_file(\$file))unlink(\$file);"]);ensure(rows('SELECT id,file,status FROM videos ORDER BY id')===$before,'Original videos changed');}
    if(is_file($replacement))unlink($replacement);
}
ensure((bool)$video,'No processed fixture');foreach($files as $file)wait(fn()=>!$storage->exists($file),180,2000);
report('php-materials-background',['passed'=>true,'normalMoodleCron'=>true,'normalVideoqScheduler'=>true,'normalSqsWorker'=>true,'sameExistingCourse'=>true,'replacementProcessed'=>true,'replacementKeepsCourse'=>true,'sourceDeletionAcknowledged'=>true,'retiredMediaDeleted'=>true,'retiredMediaCount'=>count($files),'existingVideosPreserved'=>true]);

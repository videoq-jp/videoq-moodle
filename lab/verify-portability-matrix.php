<?php
declare(strict_types=1);
require __DIR__.'/support/restore.php';require __DIR__.'/support/s3.php';
use VideoQLab\{Browser,Storage};
use function VideoQLab\{guard,ready,rows,docker,command,container,data,ensure,wait,report,moodle};
function cross_fixture(string $action): array {
    $out=docker(['exec','videoq-restore-check-moodle-restore-1','php','/lab/cross-site-fixture.php',$action]);
    $offset=strpos($out,'{"run":');ensure($offset!==false,'Missing cross-site fixture result');
    return json_decode(substr($out,$offset),true,32,JSON_THROW_ON_ERROR);
}
function matrix_question(Browser $browser,int $videoid,array &$failures,string $mode): void {
    try { restore_question($browser,$videoid); }
    catch (RuntimeException $error) {
        if ($error->getMessage()!=='Answer stream failed') throw $error;
        $failures[]=['scenario'=>$mode,'failure'=>'real-ai-stream-error','sameQuestionRetried'=>true];
        $browser->ready();restore_question($browser,$videoid);
    }
}
guard();ready();$root='https://restore.moodle.localhost:18444';$secondary='moodle_restore_check';
$installation=rows("SELECT id FROM lms_installations WHERE issuer=$1 AND owner_user_id='lti-lab-owner' AND active",[$root])[0]['id']??null;
ensure(is_string($installation),'Configure the disposable second site first');
$before=rows('SELECT id,file,status FROM videos ORDER BY id');$existingCourses=array_column(rows('SELECT id FROM video_courses'),'id');
$source=$cross=null;$targets=$files=$videos=$failures=$browserChecks=$verified=[];$storage=new Storage();
docker(['cp',data().'/acceptance/spoken-lesson.mp4',container().':/tmp/videoq-restore-acceptance.mp4']);
try {
    $source=restore_fixture('create');$original=restored_indexed($source['sources']['source']);$files[]=$original['file'];
    echo "Source indexed for partial, import and cross-site checks.\n";
    $browser=new Browser();try{$browser->login();$browser->course($source['courses']['source']);matrix_question($browser,$original['video_id'],$failures,'source');}finally{$browser->close();}
    $history=rows('SELECT * FROM chat_logs WHERE course_id=$1 ORDER BY id',[$original['course_id']]);ensure((bool)$history,'Nonempty source history required');
    foreach(['partial','import'] as $mode){$source=restore_fixture($mode);$targets[$mode]=['cmid'=>$source['sources'][$mode],'course'=>$source['courses'][$mode],'installation'=>'moodle-sync-lab','database'=>'moodle','root'=>moodle()];}
    $source=restore_fixture('archive');$archive=data().'/cross-site/archive-'.$source['run'].'.mbz';
    docker(['cp',container().':'.$source['archive'],$archive]);
    command(['sudo','-n','chmod','0600',$archive]);
    docker(['cp',$archive,'videoq-restore-check-moodle-restore-1:/tmp/videoq-cross-site.mbz']);
    $cross=cross_fixture('restore');$targets['cross-site']=['cmid'=>$cross['source'],'course'=>$cross['course'],'installation'=>$installation,'database'=>$secondary,'root'=>$root];
    echo "All three restoration paths executed; waiting for normal indexing.\n";
    foreach($targets as $mode=>$target){
        $video=restored_indexed($target['cmid'],$target['installation'],$target['database']);$videos[$mode]=$video;$files[]=$video['file'];
        ensure($video['course_id']!==$original['course_id']&&$video['video_id']!==$original['video_id']&&$video['file']!==$original['file'],'Destination reuses source identity/media');
        ensure($storage->exists($video['file']),'Destination media missing');
        ensure(!rows('SELECT id FROM chat_logs WHERE course_id=$1',[$video['course_id']]),'Private source history copied');
        foreach ($mode==='cross-site' ? ['chrome','firefox'] : ['chrome'] as $engine) {
        putenv('BROWSER='.$engine);$browser=new Browser();try{
            $browser->login('admin',$target['root']);$browser->course($target['course'],$target['root']);
            if($engine==='chrome')ensure($browser->js("return document.querySelectorAll('.answer').length")===0,'Source history appeared in destination');
            $lessons=$browser->js("return JSON.parse(document.querySelector('.local-videoq-workspace').dataset.videoqLessons)");
            ensure(count($lessons)===1&&$lessons[0]['lesson']==='sync_'.$target['cmid'],'Destination catalog differs');
            $browser->js("document.querySelectorAll('.answer').forEach(el=>el.dataset.existing='true')");
            matrix_question($browser,$video['video_id'],$failures,$mode);
            $browser->js("const nodes=document.querySelectorAll('.answer:not([data-existing])');nodes[nodes.length-1].dataset.matrixAnswer='true'");
            $answer='[data-matrix-answer]';
            foreach([['good',true],['bad',true],['bad',false]] as [$value,$pressed]) {
                $selector=$answer.' [data-feedback='.$value.']';$browser->click($selector);
                $browser->wait("const el=document.querySelector(arguments[0]);return el.getAttribute('aria-pressed')===String(arguments[1])&&!el.disabled",30,[$selector,$pressed]);
            }
            $citation=$answer.' button.citation';preg_match('/\d+:\d+(?::\d+)?/',$browser->text($citation),$match);$seconds=0;
            ensure(isset($match[0]),'Missing timestamp citation');
            foreach(explode(':',$match[0]) as $part)$seconds=$seconds*60+(int)$part;
            $browser->click($citation);$browser->wait("return Math.abs(document.querySelector('video').currentTime-arguments[0])<2",30,[$seconds]);
            $browser->js("document.querySelector('video').pause()");
            foreach([390,768,1440] as $width){$browser->resize($width);$browser->wait('return document.documentElement.scrollWidth<=innerWidth');}
            $stylesheet=$browser->js("return [...document.querySelectorAll('link[rel=stylesheet]')].map(el=>el.href).find(url=>url.includes('/theme/styles.php/'))");
            if($mode==='cross-site')ensure(str_contains($stylesheet,'/classic/'),'Classic theme was not loaded');
            $browserChecks[]=['scenario'=>$mode,'browser'=>$browser->js('return navigator.userAgent'),'stylesheet'=>$stylesheet,'feedbackGoodBadReset'=>true,'timestampSeek'=>true];
        }finally{$browser->close();}
        }
        ensure(rows('SELECT * FROM chat_logs WHERE course_id=$1 ORDER BY id',[$original['course_id']])===$history,'Source history changed');
        $verified[]=$mode;
        echo 'Real scoped answer and isolated history verified: '.$mode.".\n";
    }
    ensure(count(array_unique(array_column($videos,'course_id')))===3,'Destinations share a backend course');
    foreach($targets as $mode=>$target){if($mode==='cross-site')cross_fixture('delete');else restore_fixture('delete',$mode);}
    foreach($targets as $target)wait(fn()=>restored_deleted($target['cmid'],$target['installation'],$target['database']),420,2000);
    ensure(restore_resource($source['sources']['source'])['video_id']===$original['video_id']&&$storage->exists($original['file']),'Deleting destinations removed source video');
    ensure(rows('SELECT * FROM chat_logs WHERE course_id=$1 ORDER BY id',[$original['course_id']])===$history,'Deleting destinations changed source history');
}catch(Throwable $error){
    report('php-portability-matrix-failed',['passed'=>false,'verifiedScenarios'=>$verified,
        'lastScenario'=>$mode??'source','reason'=>'browser-or-contract-check-failed','aiStreamFailures'=>$failures,'browserChecks'=>$browserChecks]);
    throw $error;
}finally{
    if($cross){cross_fixture('delete');wait(fn()=>restored_deleted($cross['source'],$installation,$secondary),420,2000);cross_fixture('cleanup');}
    if($source){$source=restore_fixture('status');foreach(array_reverse(array_keys($source['courses'])) as $mode)restore_fixture('delete',$mode);
        foreach($source['sources'] as $cmid)wait(fn()=>restored_deleted($cmid),420,2000);restore_fixture('cleanup');}
    $courseids=[];foreach($source['sources']??[] as $cmid){$r=restore_resource($cmid);if($r&&$r['course_id'])$courseids[]=$r['course_id'];}
    if($cross){$r=restore_resource($cross['source'],$installation);if($r&&$r['course_id'])$courseids[]=$r['course_id'];}
    foreach(array_unique($courseids) as $courseid){
        ensure(!in_array($courseid,$existingCourses,true),'Refusing existing course cleanup');
        ensure(!rows('SELECT video_id FROM video_course_members WHERE course_id=$1',[$courseid]),'Fixture still has videos');
        rows('DELETE FROM lms_resources WHERE course_id=$1 AND video_id IS NULL',[$courseid]);rows('DELETE FROM lms_courses WHERE course_id=$1',[$courseid]);
        rows("DELETE FROM video_courses WHERE id=$1 AND user_id='lti-lab-owner'",[$courseid]);
    }
    if(isset($archive)&&is_file($archive))unlink($archive);
    docker(['exec',container(),'php','-r',"if(is_file('/tmp/videoq-restore-acceptance.mp4'))unlink('/tmp/videoq-restore-acceptance.mp4');"]);
    ensure(rows('SELECT id,file,status FROM videos ORDER BY id')===$before,'Existing videos changed');
}
foreach($files as $file)wait(fn()=>!$storage->exists($file),240,2000);
report('php-portability-matrix',['passed'=>true,'partialArchive'=>true,'importIntoExistingCourse'=>true,'existingEntryPreserved'=>true,
    'crossSiteIndependentInstallation'=>true,'destinationConnectionUsed'=>true,'privateHistoryNotCopied'=>true,'realAnswersScopedToDestination'=>true,
    'sourceHistoryAndMediaPreserved'=>true,'originalVideosPreserved'=>true,'retiredMediaDeleted'=>true,'aiStreamFailures'=>$failures,'browserChecks'=>$browserChecks]);

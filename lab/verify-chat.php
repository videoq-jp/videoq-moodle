<?php
declare(strict_types=1);
require __DIR__.'/support/browser.php';require __DIR__.'/support/stream.php';
use VideoQLab\Browser;
use function VideoQLab\{guard,rows,videoq,ensure,answer,report,data};
guard();\VideoQLab\ready();$live=in_array('--live',$argv,true);$videos=array_column(rows("SELECT video_id FROM lms_resources WHERE installation_id='moodle-sync-lab' AND video_id IS NOT NULL"),'video_id');
ensure((bool)$videos,'Indexed video required');
$fixture=str_replace(['VIDEOQ_ORIGIN','VIDEO_ID'],[json_encode(videoq()),(string)$videos[0]],file_get_contents(__DIR__.'/support/chat-fixture.js'));
$b=new Browser($live?'':$fixture);$new='.answer:not([data-existing])';
try {
    $b->login();$b->course();$b->js("document.querySelectorAll('.answer').forEach(el=>el.dataset.existing='true')");
    ensure($b->js("return document.querySelector('#videoq-native-question').rows")===3,'Native composer rows');
    ensure($b->js("return document.querySelector('#videoq-questions').getAttribute('aria-hidden')")==='true','Hidden authenticated transport');
    ensure($b->js("return document.querySelectorAll('#playback-controls,#current-question,#use-position,[data-question],.local-videoq-chat-link').length")===0,'Unexpected controls');
    if ($live) {
        $b->ask('この講座で説明している画像の標本化と量子化の違いを簡潔に説明してください。');
        $response=$b->tool(fn($frame)=>$frame->wait("return window.vqEvidence.findLast(event=>event.path.endsWith('/messages/stream'))",150));
        ensure($response['status']===200&&str_contains($response['contentType'],'text/event-stream'),'Actual SSE response');
        $result=answer($response['text']);ensure((bool)$result['sources'],'Actual retrieval sources');
        foreach($result['sources'] as $source)ensure(in_array($source['video_id'],$videos,true),'Unexpected source');
        ensure(strlen(implode('',array_column($result['segments'],'text')))>40,'Actual answer text');
        $b->ready();ensure($b->js('return window.vqUpdates')>1,'Incremental native rendering');
    } else {
        $b->fill('#videoq-native-question','IME入力');$b->js("document.querySelector('#videoq-native-question').dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',isComposing:true,bubbles:true,cancelable:true}))");
        ensure($b->js("return document.querySelectorAll('$new').length")===0,'IME submitted');
        $b->ask('ストリーミングを確認します');$b->wait("return document.querySelector('$new [data-region=\"text-container\"]')?.textContent.includes('最初に見える説明です。')");
        ensure($b->js("return document.querySelector('#videoq-native-controls').disabled"),'Partial answer must show while busy');
        ensure($b->js("return document.querySelectorAll('$new [data-feedback]').length")===0,'Premature feedback');
        $b->tool(fn($f)=>$f->js('window.finishStream()'));$b->ready();
        ensure($b->text($new.' strong')==='続きの説明です。','Safe rich text');
        ensure($b->js("return document.querySelectorAll('$new ul li').length")===2,'Native list formatting');
    }
    ensure($b->text($new.' h6')==='AIアシスタント','Assistant display name');
    ensure($b->js("return document.querySelectorAll('$new [data-region=\"text-container\"] img').length")===0,'Model HTML executed');
    $feedback=$new.' [data-feedback=';
    foreach([['good',true],['bad',true],['bad',false]] as [$value,$pressed]) {
        $selector=$feedback.$value.']';$b->click($selector);
        $b->wait("const el=document.querySelector(arguments[0]);return el.getAttribute('aria-pressed')===String(arguments[1])&&!el.disabled",30,[$selector,$pressed]);
    }
    if(!$live){
        $b->tool(fn($f)=>$f->js('window.failFeedback=true'));$b->click($feedback.'good]');
        $b->wait("return document.querySelector('$new [data-region=\"answer-feedback\"] [role=status]')?.textContent.includes('評価を保存できませんでした')");
        ensure($b->js("return document.querySelector('$new [data-feedback=good]').getAttribute('aria-pressed')")==='false','Failed feedback changed state');
        $b->click($feedback.'good]');$b->wait("return document.querySelector('$new [data-feedback=good]').getAttribute('aria-pressed')==='true'");
        $b->js("const bridge=window.vqBridge,child=document.querySelector('#videoq-questions').contentWindow;const data={type:'videoq:chat',version:1,channel:bridge.channel,videoId:bridge.videoId,event:'question',requestId:'f'.repeat(32),text:'FORGED'};for(const event of [{source:child,origin:'https://attacker.example',data},{source:window,origin:bridge.origin,data},{source:child,origin:bridge.origin,data:{...data,channel:'f'.repeat(32)}}])window.dispatchEvent(new MessageEvent('message',event));");
        ensure(!str_contains($b->text('#videoq-native-chat'),'FORGED'),'Forged message accepted');
        $original=$b->js("return getComputedStyle(document.querySelector('.message.send')).backgroundColor");
        $b->js("window.testStyle=document.createElement('style');testStyle.textContent='.message-app .message.send {background-color:rgb(96,54,151)!important}';document.head.append(testStyle)");
        ensure($b->js("return getComputedStyle(document.querySelector('.message.send')).backgroundColor")==='rgb(96, 54, 151)','Moodle theme inheritance');
        $b->js('testStyle.remove()');ensure($b->js("return getComputedStyle(document.querySelector('.message.send')).backgroundColor")===$original,'Theme restoration');
    }
    $citation=$new.' button.citation';preg_match('/\d+:\d+(?::\d+)?/',$b->text($citation),$match);$seconds=0;
    foreach(explode(':',$match[0]) as $part)$seconds=$seconds*60+(int)$part;
    $b->click($citation);$b->wait("return Math.abs(document.querySelector('video').currentTime-arguments[0])<2",30,[$seconds]);$b->js("document.querySelector('video').pause()");
    foreach([390,768,1280,1440] as $width){$b->resize($width);$b->wait('return document.documentElement.scrollWidth<=innerWidth');}
    if(!$live){
        $b->ask('途中切断の確認');$b->wait("return document.querySelectorAll('$new').length===2");$b->tool(fn($f)=>$f->js('window.interruptStream()'));$b->ready();
        $b->wait("return document.querySelector('#videoq-native-status').classList.contains('text-danger')");
        ensure($b->value('#videoq-native-question')==='途中切断の確認','Retry input lost');
        ensure($b->js("return [...document.querySelectorAll('$new')].at(-1).querySelectorAll('[data-feedback]').length")===0,'Interrupted answer has feedback');
    }
    $name=$live?'php-chat-live':'php-chat-browser';report($name,['passed'=>true,'realProvider'=>$live,'streaming'=>true,'nativeMoodleChat'=>true,'feedbackGoodBadReset'=>true,'timestampSeek'=>true,'responsiveWidths'=>[390,768,1280,1440],'sourceVideoIds'=>$videos]);
    $b->screenshot(data().'/acceptance/'.$name.'.png');
}finally{$b->close();}

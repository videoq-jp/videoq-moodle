<?php
// Real Moodle learners; curl_multi sends all provider questions concurrently.
declare(strict_types=1);
require __DIR__.'/support/browser.php';require __DIR__.'/support/stream.php';use VideoQLab\Browser;
use function VideoQLab\{guard,rows,docker,container,ensure,videoq,report,answer,data};
guard();$options=getopt('',['count:','live']);$count=(int)($options['count']??5);$live=isset($options['live']);ensure($count>=1&&$count<=200,'Learner count must be 1–200');
if($live){$quota=rows("SELECT ai_answers_limit,used_ai_answers FROM users WHERE id='lti-lab-owner'")[0];ensure($quota['ai_answers_limit']-$quota['used_ai_answers']>=$count,'Insufficient reserved quota');}
$accounts=rows('SELECT count(*) AS n FROM users')[0]['n'];$users=[];$tokens=[];$results=[];$handles=[];$multi=null;
try{
    $users=json_decode(docker(['exec',container(),'php','/lab/acceptance-users.php','create',(string)$count,'5']),true,32,JSON_THROW_ON_ERROR);
    foreach($users as $user){$b=new Browser();try{
        $b->login($user['username']);$b->course();
        $tokens[]=$b->tool(static function($f){$f->js(<<<'JS'
const original=window.fetch.bind(window);window.fetch=(input,init)=>{
 if(new URL(String(input),location.href).pathname.endsWith('/api/lti/messages/stream')){
  window.vqLoadToken=new Headers(init.headers).get('Authorization');
  return Promise.resolve(new Response('data: {"type":"text_delta","segmentIndex":0,"text":"Authentication capture only"}\n\ndata: {"type":"done"}\n\n',{headers:{'Content-Type':'text/event-stream'}}));
 }return original(input,init);
};document.querySelector('#question').value='Authentication capture';document.querySelector('#form').requestSubmit();
JS);return $f->wait('return window.vqLoadToken');});
        echo "Signed learner launch ready.\n";
    }finally{$b->close();}}
    if($live){
        $bundle=tempnam(sys_get_temp_dir(),'videoq-public-ca-');file_put_contents($bundle,docker(['exec',container(),'cat','/var/www/moodledata/moodleorgca.crt']));$multi=curl_multi_init();$began=microtime(true);
        foreach($tokens as $token){$curl=curl_init(videoq().'/api/lti/messages/stream');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>300,CURLOPT_CAINFO=>$bundle,
            CURLOPT_HTTPHEADER=>['Authorization: '.$token,'Content-Type: application/json','Origin: '.videoq()],CURLOPT_POSTFIELDS=>json_encode(['messages'=>[['role'=>'user','content'=>'この講座で説明する画像の標本化と量子化の違いを短く説明してください。']]])]);$handles[]=$curl;curl_multi_add_handle($multi,$curl);}
        do{$status=curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1);}while($running&&$status===CURLM_OK);
        foreach($handles as $curl){$ok=false;$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);try{$reply=answer(curl_multi_getcontent($curl));$ok=$status===200&&(bool)$reply['sources']&&(bool)trim(implode('',array_column($reply['segments'],'text')));}catch(Throwable){}$results[]=['ok'=>$ok,'httpStatus'=>$status,'milliseconds'=>(int)((microtime(true)-$began)*1000)];curl_multi_remove_handle($multi,$curl);curl_close($curl);}
        $handles=[];curl_multi_close($multi);$multi=null;unlink($bundle);
    }
    ensure(rows('SELECT count(*) AS n FROM users')[0]['n']===$accounts,'Extra VideoQ accounts created');
    $passed=!in_array(false,array_column($results,'ok'),true);
    report('php-load-'.$count,['passed'=>$passed,'realMoodleLogins'=>count($tokens),'realAi'=>$live,'questionsStartedConcurrently'=>$live,'simultaneousVideoPlaybackTested'=>false,'additionalVideoqAccounts'=>0,'results'=>$results]);ensure($passed,'Concurrent provider question failed');
}finally{
    if($multi){foreach($handles as $handle){curl_multi_remove_handle($multi,$handle);curl_close($handle);}curl_multi_close($multi);}if(isset($bundle)&&is_file($bundle))unlink($bundle);
    docker(['exec',container(),'php','/lab/acceptance-users.php','cleanup',(string)$count,'5']);
}

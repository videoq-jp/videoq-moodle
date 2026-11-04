<?php
declare(strict_types=1);
require __DIR__.'/support/browser.php';use VideoQLab\Browser;
use function VideoQLab\{guard,rows,ensure,request,videoq,report};
guard();$issuer='https://disconnect.videoq.example';$installation=$secret=null;
$snapshot=static fn()=>rows("SELECT (SELECT coalesce(jsonb_agg(to_jsonb(v) ORDER BY v.id),'[]'::jsonb) FROM videos v) AS videos,(SELECT count(*) FROM scene_embeddings) AS vectors,(SELECT count(*) FROM chat_logs) AS logs,(SELECT count(*) FROM video_course_members) AS memberships");
$before=$snapshot();$connections=rows('SELECT id,active FROM lms_installations ORDER BY id');
\VideoQLab\ready();
$anonymous=request(videoq().'/api/lms/pairing/connections/unknown/disconnect',[])['status'];
ensure($anonymous===401,'Expected anonymous disconnect rejection; received HTTP '.$anonymous);
$b=new Browser();$button=static fn($label,$root)=>$b->js("const el=[...document.querySelector(arguments[0]).querySelectorAll('button')].find(el=>el.textContent.trim()===arguments[1]);if(!el)throw new Error('Missing button');el.click()",[$root,$label]);
try{
    $b->login('videoq_lab',videoq());$b->go(videoq().'/settings#moodle');$b->fill('#moodle-url',$issuer);$b->click('#moodle form button[type=submit]');$b->element('#moodle-code');$code=$b->value('#moodle-code');
    ensure(str_starts_with($code,'VIDEOQ2.'),'Materials-capable server required');$secret=json_decode(base64_decode(substr($code,8)),true,16,JSON_THROW_ON_ERROR)['secret'];
    $body=['secret'=>$secret,'issuer'=>$issuer,'clientId'=>'disconnect-'.bin2hex(random_bytes(16)),'deploymentId'=>'1','mode'=>'materials','materialsService'=>['tokenEndpoint'=>$issuer.'/local/videoq/token.php','endpoint'=>$issuer.'/local/videoq/service.php']];
    $wrong=$body;$wrong['issuer']='https://wrong.videoq.example';$wrong['materialsService']=['tokenEndpoint'=>'https://wrong.videoq.example/token','endpoint'=>'https://wrong.videoq.example/service'];
    ensure(request(videoq().'/api/lms/pairing/exchange',$wrong)['status']===401,'Wrong issuer accepted');
    $response=request(videoq().'/api/lms/pairing/exchange',$body);ensure($response['status']===200,'Pairing failed');$installation=json_decode($response['text'],true,16,JSON_THROW_ON_ERROR)['installationId'];
    $response=request(videoq().'/api/lms/pairing/exchange',$body);ensure($response['status']===200&&json_decode($response['text'],true)['installationId']===$installation,'Lost response recovery failed');
    $root='[data-connection-id="'.$installation.'"]';$b->refresh();$b->element($root);
    $button('接続を解除',$root);$button('キャンセル',$root);ensure(rows('SELECT active FROM lms_installations WHERE id=$1',[$installation])[0]['active'],'Cancel revoked connection');
    $button('接続を解除',$root);$button('解除する',$root);$b->wait("return document.querySelector(arguments[0])?.textContent.includes('接続解除済み')",30,[$root]);
    ensure(!rows('SELECT active FROM lms_installations WHERE id=$1',[$installation])[0]['active'],'Connection not revoked');ensure(request(videoq().'/api/lms/pairing/exchange',$body)['status']===401,'Disconnected exchange recovered');
    $b->refresh();$b->wait("return document.querySelector(arguments[0])?.textContent.includes('接続解除済み')",30,[$root]);
    ensure($snapshot()===$before,'Learning data changed');ensure(rows('SELECT id,active FROM lms_installations WHERE id<>$1 ORDER BY id',[$installation])===$connections,'Other connections changed');
    report('php-connection',['passed'=>true,'materialsMode'=>true,'wrongIssuerRejected'=>true,'lostResponseRecovery'=>true,'cancelPreservesConnection'=>true,'disconnectedStatePersists'=>true,'learningDataPreserved'=>true,'otherConnectionsPreserved'=>true]);
}finally{
    $b->close();if($installation)rows('DELETE FROM lms_installations WHERE id=$1 AND issuer=$2 AND owner_user_id=$3',[$installation,$issuer,'lti-lab-owner']);
    if($secret)foreach(['lti-materials-pairing:','moodle-pairing:'] as $prefix)rows("DELETE FROM lti_credentials WHERE id=$1 AND kind IN('materials-pairing','moodle-pairing-code')",[hash('sha256',$prefix.$secret)]);
}

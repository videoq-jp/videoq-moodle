<?php
// Establish an independent second-site connection through the public sign-in/pairing APIs.
declare(strict_types=1);
require __DIR__.'/support/runtime.php';
use function VideoQLab\{guard,videoq,docker,container,ensure};
guard(false);$root='https://restore.moodle.localhost:18444';
$bundle=tempnam(sys_get_temp_dir(),'restore-ca-');file_put_contents($bundle,docker(['exec',container(),'cat','/var/www/moodledata/moodleorgca.crt']));
$c=curl_init();curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_CAINFO=>$bundle,CURLOPT_TIMEOUT=>60,
    CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Origin: '.videoq()]]);
try {
    curl_setopt_array($c,[CURLOPT_URL=>videoq().'/api/auth/sign-in/username',CURLOPT_POSTFIELDS=>json_encode(['username'=>'videoq_lab','password'=>getenv('LAB_PASSWORD')?:'Lti-lab-only-123!'])]);
    $body=curl_exec($c);ensure($body!==false&&curl_getinfo($c,CURLINFO_RESPONSE_CODE)===200,'Fixture owner sign-in failed');
    curl_setopt_array($c,[CURLOPT_URL=>videoq().'/api/lms/pairing',CURLOPT_POSTFIELDS=>json_encode(['moodleUrl'=>$root])]);
    $body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);
    $failure=json_decode((string)$body,true);$failure=is_string($failure['error']??null)&&preg_match('/^[A-Z0-9_]+$/D',$failure['error'])?$failure['error']:'unknown';
    ensure($body!==false&&$status===200,'Second-site pairing code failed: HTTP '.$status.', '.$failure);
    $result=json_decode($body,true,16,JSON_THROW_ON_ERROR);$code=$result['code']??null;
    ensure(is_string($code)&&str_starts_with($code,'VIDEOQ2.'),'Materials pairing code required');
    $connected=json_decode(docker(['exec','-i','videoq-restore-check-moodle-restore-1','php','/lab/connect-restored-site.php'],json_encode(['code'=>$code])),true,16,JSON_THROW_ON_ERROR);
    ensure($connected['connected']===true,'Second-site connection incomplete');
    echo "Independent second Moodle connection configured.\n";
}finally{
    // Revoke only the cookie session created by this helper.
    curl_setopt_array($c,[CURLOPT_URL=>videoq().'/api/auth/sign-out',CURLOPT_POSTFIELDS=>'{}']);
    curl_exec($c);curl_close($c);unlink($bundle);
}

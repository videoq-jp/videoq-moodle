<?php
declare(strict_types=1);
require __DIR__.'/support/runtime.php';
use function VideoQLab\{guard,rows,docker,container,data,fixture,ensure,moodle,report};
guard();$before=rows('SELECT id,status,file FROM videos ORDER BY id');
$config=rows("SELECT name,value FROM mdl_config_plugins WHERE plugin='local_videoq' ORDER BY name",[], 'moodle');
$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);ensure($key!==false,'Cannot generate disposable key');
$public=openssl_pkey_get_details($key)['key'];$state=null;
$encode=static fn(string $v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');
$signed=static function(array $claims)use($key,$encode):string{
    $input=$encode('{"alg":"RS256","typ":"JWT"}').'.'.$encode(json_encode($claims,JSON_THROW_ON_ERROR));
    ensure(openssl_sign($input,$signature,$key,OPENSSL_ALGO_SHA256),'Cannot sign assertion');return $input.'.'.$encode($signature);
};
$media=data().'/acceptance/spoken-lesson.mp4';ensure(is_file($media),'Spoken fixture required');
docker(['cp',$media,container().':/tmp/videoq-materials-spoken.mp4']);
try {
    $state=fixture('materials-fixture.php','setup',['run'=>bin2hex(random_bytes(16)),'publicKey'=>$public]);$now=time();
    $claims=['iss'=>$state['clientid'],'sub'=>$state['clientid'],'aud'=>moodle().'/local/videoq/token.php','iat'=>$now,'exp'=>$now+60,'jti'=>bin2hex(random_bytes(16)),
        'https://purl.imsglobal.org/spec/lti/claim/deployment_id'=>(string)$state['typeid']];
    $wrong=$claims;$wrong['aud']='https://attacker.example/token';$wrong['jti']=bin2hex(random_bytes(16));
    $result=fixture('check-materials-service.php',null,['jwt'=>$signed($claims),'wrongAudience'=>$signed($wrong)]);
    ensure($result['passed']&&$result['transactionRolledBack'],'Service contracts failed');
} finally {
    if($state)ensure(fixture('materials-fixture.php','cleanup')['removed'],'Fixture cleanup failed');
    docker(['exec',container(),'php','-r',"if(is_file('/tmp/videoq-materials-spoken.mp4'))unlink('/tmp/videoq-materials-spoken.mp4');"]);
}
ensure(rows('SELECT id,status,file FROM videos ORDER BY id')===$before,'Existing videos changed');
ensure(rows("SELECT name,value FROM mdl_config_plugins WHERE plugin='local_videoq' ORDER BY name",[],'moodle')===$config,'Configuration not restored');
report('php-materials-service-contract',$result);

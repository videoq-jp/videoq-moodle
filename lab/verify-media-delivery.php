<?php
// Real authentication and byte-range checks for the lab's private media offload.
declare(strict_types=1);
require __DIR__ . '/support/browser.php';
use VideoQLab\Browser;
use function VideoQLab\{guard, docker, container, ensure, report, moodle};
guard();
$metadata=json_decode(docker(['exec',container(),'php','-r', <<<'CODE'
define('CLI_SCRIPT',true);require('/var/www/html/config.php');if($CFG->wwwroot!=='https://moodle.localhost:18443')throw new RuntimeException('Lab only');$f=\local_videoq\synchronizer::video_file(82);$h=$f->get_content_file_handle();$prefix=fread($h,65536);fclose($h);echo json_encode(['hash'=>$f->get_contenthash(),'prefixSha256'=>hash('sha256',$prefix),'size'=>$f->get_filesize()]);
CODE]),true,32,JSON_THROW_ON_ERROR);
$bundle=tempnam(sys_get_temp_dir(),'media-ca-');file_put_contents($bundle,docker(['exec',container(),'cat','/var/www/moodledata/moodleorgca.crt']));
function media_check(string $url,string $bundle,string $cookie='',array $extra=[]):array {
    $body='';$headers=[];$c=curl_init($url);
    curl_setopt_array($c,[CURLOPT_CAINFO=>$bundle,CURLOPT_TIMEOUT=>45,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIE=>$cookie,CURLOPT_HTTPHEADER=>['Range: bytes=0-65535',...$extra],
        CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$headers){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);},
        CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$body){$body.=$chunk;return strlen($body)>131072?0:strlen($chunk);}]);
    curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return ['status'=>$status,'headers'=>$headers,'body'=>$body];
}
$b=new Browser();
try {
    $b->login();$b->course();$media=$b->jsAsync("const done=arguments[arguments.length-1];fetch(document.querySelector('video').currentSrc,{method:'HEAD'}).then(r=>done({url:r.url,status:r.status,size:Number(r.headers.get('content-length'))})).catch(()=>done(null))");
    ensure(($media['status']??0)===200&&$media['size']===(int)$metadata['size'],'Authenticated media HEAD failed: status=' . ($media['status']??0) . ', bytes=' . ($media['size']??0) . ', expected=' . $metadata['size']);
    $cookie=implode('; ',array_map(fn($c)=>$c['name'].'='.$c['value'],$b->cookies()));
    $valid=media_check($media['url'],$bundle,$cookie);
    ensure($valid['status']===206&&strlen($valid['body'])===65536,'Authenticated range failed');
    ensure(hash_equals($metadata['prefixSha256'],hash('sha256',$valid['body'])),'Offload changed source bytes');
    ensure(($valid['headers']['x-moodle-media-offload']??'')==='1'&&!isset($valid['headers']['x-accel-redirect']),'Offload validation failed: active=' . ($valid['headers']['x-moodle-media-offload']??'absent') . ', internalHeader=' . (isset($valid['headers']['x-accel-redirect'])?'present':'absent'));
    ensure(str_starts_with($valid['headers']['content-type']??'','video/'),'Video MIME type lost');
    $hash=$metadata['hash'];$internal=moodle().'/protected_moodle/'.substr($hash,0,2).'/'.substr($hash,2,2).'/'.$hash;
    foreach([[$media['url'],[]],[$internal,[]],[$internal,['X-Accel-Redirect: '.rawurlencode(parse_url($internal,PHP_URL_PATH))]]] as [$url,$extra]){
        $r=media_check($url,$bundle,'',$extra);
        ensure($r['status']!==206&&($r['headers']['x-moodle-media-offload']??'')!=='1'&&!str_starts_with($r['headers']['content-type']??'','video/'),'Anonymous or forged-header access exposed media');
    }
    $b->clearCookies(); $b->login('learner');
    $cookie=implode('; ',array_map(fn($c)=>$c['name'].'='.$c['value'],$b->cookies()));
    $r=media_check($media['url'],$bundle,$cookie);
    ensure($r['status']!==206&&($r['headers']['x-moodle-media-offload']??'')!=='1'&&!str_starts_with($r['headers']['content-type']??'','video/'),'Unenrolled learner received media');
    report('php-media-offload',['passed'=>true,'authenticatedHead'=>true,'authenticatedRange'=>true,'sourceBytesMatch'=>true,
        'videoMimePreserved'=>true,'anonymousDenied'=>true,'unenrolledLearnerDenied'=>true,'directPrivatePathDenied'=>true,'forgedRequestHeaderDenied'=>true,'internalHeaderNotExposed'=>true]);
}finally{$b->close();unlink($bundle);}

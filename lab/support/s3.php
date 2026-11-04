<?php
// Small SigV4 client for the isolated lab's public Garage fixture credentials.
declare(strict_types=1);
namespace VideoQLab;
require_once __DIR__.'/runtime.php';
final class Storage {
    public function operation(string $method,string $key,?string $file=null): array {
        guard();$path='/lti-sync/'.implode('/',array_map('rawurlencode',explode('/',$key)));
        $date=gmdate('Ymd');$now=gmdate('Ymd\THis\Z');$host='storage.videoq.localhost:18443';
        $hash=$method==='PUT'?hash_file('sha256',$file):hash('sha256','');
        $headers="host:$host\nx-amz-content-sha256:$hash\nx-amz-date:$now\n";
        $signed='host;x-amz-content-sha256;x-amz-date';$scope=$date.'/garage/s3/aws4_request';
        $canonical=$method."\n".$path."\n\n".$headers."\n".$signed."\n".$hash;
        $material='AWS4-HMAC-SHA256'."\n".$now."\n".$scope."\n".hash('sha256',$canonical);
        $secret=str_repeat('0',64);$access='GK'.str_repeat('0',32);
        $signing=hash_hmac('sha256',$date,'AWS4'.$secret,true);
        foreach(['garage','s3','aws4_request'] as $part)$signing=hash_hmac('sha256',$part,$signing,true);
        $signature=hash_hmac('sha256',$material,$signing);
        $ca=tempnam(sys_get_temp_dir(),'videoq-public-ca-');file_put_contents($ca,docker(['exec',container(),'cat','/var/www/moodledata/moodleorgca.crt']));
        $curl=curl_init('https://'.$host.$path);$stream=null;
        try {
            curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>300,CURLOPT_CAINFO=>$ca,
                CURLOPT_HTTPHEADER=>['x-amz-date: '.$now,'x-amz-content-sha256: '.$hash,'Authorization: AWS4-HMAC-SHA256 Credential='.$access.'/'.$scope.', SignedHeaders='.$signed.', Signature='.$signature]]);
            if($method==='HEAD')curl_setopt($curl,CURLOPT_NOBODY,true);
            if($method==='PUT'){$stream=fopen($file,'rb');curl_setopt_array($curl,[CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$stream,CURLOPT_INFILESIZE=>filesize($file)]);}
            if($method==='GET'&&$file){$stream=fopen($file,'wb');chmod($file,0600);curl_setopt($curl,CURLOPT_FILE,$stream);}
            $text=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);ensure($text!==false,'Storage transport failed');
            return ['status'=>$status,'text'=>is_string($text)?$text:''];
        } finally {curl_close($curl);if(is_resource($stream))fclose($stream);unlink($ca);}
    }
    public function exists(string $file): bool {
        $status=$this->operation('HEAD','media/'.$file)['status'];ensure(in_array($status,[200,404],true),'Unexpected storage status');return $status===200;
    }
}

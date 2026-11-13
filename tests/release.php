<?php
declare(strict_types=1);
require dirname(__DIR__) . '/scripts/publish-release.php';
function release_check(bool $value, string $label): void { if (!$value) throw new RuntimeException($label); }
$directory = sys_get_temp_dir() . '/videoq-release-test-' . bin2hex(random_bytes(8)); mkdir($directory,0700);
try {
    foreach (['local_videoq.zip'] as $name) file_put_contents($directory.'/'.$name, 'fixture '.$name);
    $manifest=''; foreach (['local_videoq.zip'] as $name) $manifest.=hash_file('sha256',$directory.'/'.$name).'  '.$name."\n";
    file_put_contents($directory.'/SHA256SUMS',$manifest);
    foreach (['publish-failure-before','publish-failure-after','create-failure-after','upload-failure-after','already-published','asset-mismatch','authorization-denied'] as $scenario) {
        $release = null; $calls=[]; $failed=false; $pauses=0; $uploads=0; $patches=0;
        if (in_array($scenario,['already-published','asset-mismatch'],true)) {
            $assets=[]; foreach (['local_videoq.zip','SHA256SUMS'] as $name) $assets[]=['name'=>$name,'state'=>'uploaded','digest'=>'sha256:'.hash_file('sha256',$directory.'/'.$name)];
            if ($scenario==='asset-mismatch') $assets[0]['digest']='sha256:wrong';
            $release=['id'=>12,'tag_name'=>'v0.9.0','draft'=>false,'assets'=>$assets];
        }
        $request=static function($method,$url,$body,$file) use (&$release,&$calls,&$failed,&$uploads,&$patches,$scenario):array {
            $calls[]=$method;
            if ($scenario==='authorization-denied') return ['status'=>403,'body'=>[]];
            if ($method==='GET' && str_contains($url,'/tags/')) return ['status'=>$release&&!$release['draft']?200:404,'body'=>$release??[]];
            if ($method==='GET') return ['status'=>200,'body'=>$release?[$release]:[]];
            if ($method==='POST' && !$file) {
                $release=['id'=>12,'tag_name'=>'v0.9.0','draft'=>true,'assets'=>[]];
                if ($scenario==='create-failure-after'&&!$failed){$failed=true;return ['status'=>500,'body'=>[]];}
                return ['status'=>201,'body'=>$release];
            }
            if ($method==='POST' && $file) {
                $uploads++;$asset=['name'=>basename($file),'state'=>'uploaded','digest'=>'sha256:'.hash_file('sha256',$file)];$release['assets'][]=$asset;
                if ($scenario==='upload-failure-after'&&!$failed){$failed=true;return ['status'=>500,'body'=>[]];}
                return ['status'=>201,'body'=>$asset];
            }
            if ($method==='PATCH') {
                $patches++;
                if ($scenario==='publish-failure-before'&&!$failed){$failed=true;return ['status'=>500,'body'=>[]];}
                $release['draft']=false;
                if ($scenario==='publish-failure-after'&&!$failed){$failed=true;return ['status'=>500,'body'=>[]];}
                return ['status'=>200,'body'=>$release];
            }
            throw new RuntimeException('Unexpected release mutation');
        };
        $publisher=new ReleasePublisher($request,static function($seconds)use(&$pauses){$pauses++;});$error=false;
        try{$result=$publisher->publish('example/videoq-moodle','v0.9.0',$directory);}catch(RuntimeException){$error=true;}
        if(in_array($scenario,['asset-mismatch','authorization-denied'],true)) {
            release_check($error&&$uploads===0&&$patches===0&&$pauses===0,$scenario.' must fail without mutation/retry');
        }else{
            release_check(!$error&&$result['published']&&!$release['draft']&&count($release['assets'])===2,$scenario.' incomplete');
            release_check($uploads===($scenario==='already-published'?0:2),$scenario.' duplicated asset upload');
            release_check($pauses===($scenario==='already-published'?0:1),$scenario.' incorrect reconciliation');
            release_check(!in_array('DELETE',$calls,true),'Release was deleted');
        }
    }
    echo "PHP release reconciliation, ambiguous failures, published-asset preservation and authorization checks passed.\n";
}finally{foreach(scandir($directory) as $name)if($name!=='.'&&$name!=='..')unlink($directory.'/'.$name);rmdir($directory);}

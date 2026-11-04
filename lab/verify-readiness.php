<?php
// Bounded outage check for the disposable lab; always restart its database.
declare(strict_types=1);
require __DIR__.'/support/runtime.php';use function VideoQLab\{guard,request,videoq,docker,ensure,wait,report};
guard();$status=static fn($path)=>request(videoq().'/'.$path)['status'];$db=getenv('LAB_DB_CONTAINER')?:'videoq-lti-lab-db-1';
$before=['health'=>$status('health'),'ready'=>$status('ready')];ensure($before===['health'=>200,'ready'=>200],'Service not initially ready');
try{docker(['stop',$db]);$during=['health'=>$status('health'),'ready'=>$status('ready')];ensure($during===['health'=>200,'ready'=>503],'Readiness did not reflect outage');}
finally{docker(['start',$db]);wait(fn()=>$status('ready')===200,40);}
$after=['health'=>$status('health'),'ready'=>$status('ready')];ensure($after===$before,'Readiness did not recover');report('php-readiness',compact('before','during','after'));

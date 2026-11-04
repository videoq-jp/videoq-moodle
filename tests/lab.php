<?php
// Offline PHP contracts; no framework or package installation.
declare(strict_types=1);
require dirname(__DIR__).'/lab/support/runtime.php';require dirname(__DIR__).'/lab/support/stream.php';
use VideoQLab\Events;use function VideoQLab\{ensure,answer,request,wait};
$data="data: {\"text\":\"日本語\"}\r\n\r\ndata: second\r\n\r\n";
for($i=0;$i<=strlen($data);$i++){$parser=new Events();ensure([...$parser->feed(substr($data,0,$i)),...$parser->feed(substr($data,$i))]===['{"text":"日本語"}','second'],'SSE byte split');}
$parser=new Events();$events=[];foreach(str_split($data) as $byte)$events=[...$events,...$parser->feed($byte)];ensure(count($events)===2,'Byte-by-byte SSE');
$events=[['type'=>'source','source'=>['id'=>2,'video_id'=>27]],['type'=>'text_delta','segmentIndex'=>1,'text'=>'後'],['type'=>'text_delta','segmentIndex'=>0,'text'=>'先'],['type'=>'citation','segmentIndex'=>0,'sourceId'=>2],['type'=>'citation','segmentIndex'=>0,'sourceId'=>2]];
$encoded=implode('',array_map(static fn($event)=>'data: '.json_encode($event)."\n\n",$events));
$failed=false;try{answer($encoded);}catch(RuntimeException){$failed=true;}ensure($failed,'Incomplete stream accepted');
$result=answer($encoded."data: {\"type\":\"done\"}\n\n");ensure($result['segments']===[['text'=>'先','sourceIds'=>[2]],['text'=>'後','sourceIds'=>[]]],'Segment ordering or duplicate citation');
$failed=false;try{answer("data: {\"type\":\"error\"}\n\n");}catch(RuntimeException){$failed=true;}ensure($failed,'Error stream accepted');
$socket=stream_socket_server('tcp://127.0.0.1:0',$code,$message);$address=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$address,dirname(__DIR__).'/lab/mock-ai.php'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
ensure(is_resource($process),'Cannot start PHP mock');
try {
    wait(static function()use($address){try{return request('http://'.$address)['status']===405;}catch(Throwable){return false;}},10);
    $post=static fn($path,$body)=>request('http://'.$address.$path,$body);
    $transcript=json_decode($post('/v1/audio/transcriptions',[])['text'],true,32,JSON_THROW_ON_ERROR);ensure(count($transcript['segments'])===3&&strlen($transcript['text'])>0,'Transcription schema');
    $embedded=json_decode($post('/v1/embeddings',['input'=>['first','second']])['text'],true,32,JSON_THROW_ON_ERROR);ensure(count($embedded['data'])===2&&count($embedded['data'][0]['embedding'])===1536,'Embedding schema');
    $body=['messages'=>[],'tools'=>[['function'=>['name'=>'search_scenes']]]];
    $search=json_decode($post('/v1/chat/completions',$body)['text'],true,32,JSON_THROW_ON_ERROR);ensure($search['choices'][0]['finish_reason']==='tool_calls','Tool selection');
    $body['messages']=[['role'=>'tool']];$body['stream']=true;$response=$post('/v1/chat/completions',$body);$stream=(new Events())->feed($response['text']);
    ensure(str_contains($response['contentType'],'text/event-stream')&&end($stream)==='[DONE]','Mock stream completion');
    ensure(isset(json_decode($stream[count($stream)-2],true)['usage']),'Missing stream usage');
    $choice=json_decode($stream[1],true);$reply=json_decode($choice['choices'][0]['delta']['content'],true);ensure($reply['segments'][0]['sourceIds']===[1],'Mock sources');
    ensure($post('/missing',[])['status']===404,'Unknown endpoint');
    $curl=curl_init('http://'.$address.'/v1/chat/completions');curl_setopt_array($curl,[CURLOPT_POSTFIELDS=>'[]',CURLOPT_RETURNTRANSFER=>true]);curl_exec($curl);ensure(curl_getinfo($curl,CURLINFO_RESPONSE_CODE)===400,'Invalid JSON shape');curl_close($curl);
}finally{proc_terminate($process);proc_close($process);}
echo "PHP SSE byte boundaries, answer integrity and actual mock HTTP contracts passed.\n";

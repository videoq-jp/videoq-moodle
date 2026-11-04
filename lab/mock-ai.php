<?php
// Router for PHP's built-in development server; deterministic inference, no paid calls.
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
function mock_reply(array $body): never {header('Content-Type: application/json');echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function mock_event(array $event): void {echo 'data: '.json_encode($event,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n\n";flush();}
if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);exit;}
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if ($length<0||$length>16*1024*1024) {http_response_code(413);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$scenes=json_decode(file_get_contents(__DIR__.'/lesson-scenes.json'),true,16,JSON_THROW_ON_ERROR);
if ($path==='/v1/audio/transcriptions') {
    $segments=[];foreach($scenes as $i=>$scene)$segments[]=['id'=>$i,'start'=>$i*8,'end'=>($i+1)*8,'text'=>$scene['text']];
    mock_reply(['task'=>'transcribe','language'=>'ja','duration'=>24,'text'=>implode('',array_column($scenes,'text')),'segments'=>$segments]);
}
try {$object=json_decode(file_get_contents('php://input') ?: '{}',false,32,JSON_THROW_ON_ERROR);}
catch(Throwable){http_response_code(400);exit;}
if (!is_object($object)) {http_response_code(400);exit;}
$body=(array)$object;
if ($path==='/v1/embeddings') {
    $inputs=is_array($body['input']??null)?$body['input']:[$body['input']??''];$values=[];
    foreach($inputs as $i=>$input)$values[]=['object'=>'embedding','index'=>$i,'embedding'=>[1,...array_fill(0,1535,0)]];
    mock_reply(['object'=>'list','data'=>$values,'usage'=>['prompt_tokens'=>1,'total_tokens'=>1]]);
}
if ($path!=='/v1/chat/completions') {http_response_code(404);exit;}
$searched=false;$tools=false;
foreach(($body['messages']??[]) as $message) if(($message->role??'')==='tool')$searched=true;
foreach(($body['tools']??[]) as $tool) if(($tool->function->name??'')==='search_scenes')$tools=true;
$message=['role'=>'assistant','content'=>json_encode(['segments'=>[['text'=>'この教材では、LMSとVideoQをLTIで接続します。','sourceIds'=>[1]]]],JSON_UNESCAPED_UNICODE)];
if ($tools&&!$searched)$message=['role'=>'assistant','content'=>null,'tool_calls'=>[['id'=>'lab-search','type'=>'function','function'=>['name'=>'search_scenes','arguments'=>json_encode(['query'=>'LTI lab lesson','video_ids'=>null])]]]];
$id=bin2hex(random_bytes(16));$finish=isset($message['tool_calls'])?'tool_calls':'stop';$usage=['prompt_tokens'=>1,'completion_tokens'=>1,'total_tokens'=>2];
if (!empty($body['stream'])) {
    header('Content-Type: text/event-stream; charset=utf-8');header('X-Accel-Buffering: no');
    mock_event(['id'=>$id,'choices'=>[['index'=>0,'delta'=>['role'=>'assistant','content'=>''],'finish_reason'=>null]]]);
    $delta=['content'=>$message['content']];
    if($finish==='tool_calls'){$calls=[];foreach($message['tool_calls'] as $i=>$call)$calls[]=['index'=>$i,...$call];$delta=['tool_calls'=>$calls];}
    mock_event(['id'=>$id,'choices'=>[['index'=>0,'delta'=>$delta,'finish_reason'=>null]]]);
    mock_event(['id'=>$id,'choices'=>[['index'=>0,'delta'=>new stdClass(),'finish_reason'=>$finish]]]);
    mock_event(['id'=>$id,'choices'=>[],'usage'=>$usage]);echo "data: [DONE]\n\n";exit;
}
mock_reply(['id'=>$id,'object'=>'chat.completion','created'=>1,'model'=>'lab-model','choices'=>[['index'=>0,'message'=>$message,'finish_reason'=>$finish]],'usage'=>$usage]);

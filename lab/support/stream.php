<?php
declare(strict_types=1);
namespace VideoQLab;
final class Events {
    private string $buffer = '';
    public function feed(string $chunk): array {
        $this->buffer = str_replace("\r\n","\n",$this->buffer.$chunk); $events=[];
        while (($boundary=strpos($this->buffer,"\n\n")) !== false) {
            $block=substr($this->buffer,0,$boundary);$this->buffer=substr($this->buffer,$boundary+2);$data=[];
            foreach (explode("\n",$block) as $line) if (str_starts_with($line,'data:')) $data[]=ltrim(substr($line,5),' ');
            if ($data) $events[]=implode("\n",$data);
        }
        return $events;
    }
}
function answer(string $stream): array {
    $segments=$sources=[];$done=false;
    foreach ((new Events())->feed($stream) as $raw) {
        $event=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$kind=$event['type']??'';
        ensure($kind !== 'error','Answer stream failed');
        if ($kind==='source') $sources[$event['source']['id']]=$event['source'];
        if ($kind==='text_delta'||$kind==='citation') {
            $index=$event['segmentIndex'];$segments[$index]??=['text'=>'','sourceIds'=>[]];
            if ($kind==='text_delta') $segments[$index]['text'].=$event['text'];
            elseif (!in_array($event['sourceId'],$segments[$index]['sourceIds'],true)) $segments[$index]['sourceIds'][]=$event['sourceId'];
        }
        if ($kind==='done') $done=true;
    }
    ensure($done,'Answer stream did not complete');ksort($segments);
    return ['segments'=>array_values($segments),'sources'=>array_values($sources)];
}

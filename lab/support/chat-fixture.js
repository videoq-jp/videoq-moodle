if(location.origin===VIDEOQ_ORIGIN){
 const original=window.fetch.bind(window);
 window.feedbackValues=[];window.failFeedback=false;
 window.fetch=async(input,init)=>{
  const path=new URL(String(input),location.href).pathname;
  if(path.endsWith('/api/lti/feedback')){
   const body=JSON.parse(init.body);window.feedbackValues.push(body.feedback);
   if(window.failFeedback){window.failFeedback=false;return new Response('{}',{status:500});}
   return new Response(JSON.stringify({feedback:body.feedback}),{headers:{'Content-Type':'application/json'}});
  }
  if(!path.endsWith('/api/lti/messages/stream')) return original(input,init);
  return new Response(new ReadableStream({start(controller){
   const encoder=new TextEncoder();const emit=event=>controller.enqueue(encoder.encode('data: '+JSON.stringify(event)+'\r\n\r\n'));
   const first=encoder.encode('data: '+JSON.stringify({type:'source',source:{id:1,video_id:VIDEO_ID,title:'動画教材',start_time:'00:00:08',end_time:'00:00:16'}})+'\r\n\r\n');
   const split=first.findIndex(byte=>byte>=0xe0)+1;
   controller.enqueue(first.slice(0,split));controller.enqueue(first.slice(split,-1));controller.enqueue(first.slice(-1));
   emit({type:'text_delta',segmentIndex:0,text:'最初に見える説明です。<img src=x onerror="alert(1)">'});
   window.finishStream=()=>{emit({type:'text_delta',segmentIndex:0,text:'\n\n**続きの説明です。**\n\n- 動画を見る\n- 質問する'});emit({type:'citation',segmentIndex:0,sourceId:1});emit({type:'done',chat_log_id:1234,feedbackId:'b'.repeat(64),feedback:null});controller.close();};
   window.interruptStream=()=>controller.close();
  }}),{headers:{'Content-Type':'text/event-stream'}});
 };
}

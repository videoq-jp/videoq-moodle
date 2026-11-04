if (location.origin === VIDEOQ_ORIGIN) {
 const original = window.fetch.bind(window); window.vqEvidence=[];
 window.fetch=async function(input, init) {
  const response=await original(input,init), path=new URL(String(input),location.href).pathname;
  if(path.startsWith('/api/lti/')) response.clone().text().then(text=>{
   if(path.endsWith('/session')&&response.ok) window.vqSession=JSON.parse(text);
   else window.vqEvidence.push({path,status:response.status,contentType:response.headers.get('content-type'),text});
  }); return response;
 };
}
if (location.origin === MOODLE_ORIGIN) {
 window.vqBridge=null;window.vqUpdates=0;
 window.addEventListener('message',event=>{
  if(event.origin===VIDEOQ_ORIGIN&&event.data?.type==='videoq:ready') window.vqBridge={...event.data,origin:event.origin};
  if(event.origin===VIDEOQ_ORIGIN&&event.data?.event==='answer-update') window.vqUpdates++;
 });
}

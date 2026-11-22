function notificationIcon(){
 const role=new URL(self.location.origin).hostname==='yonetim.omnigoglobal.com'?'admin':'client';
 const agent=self.navigator?.userAgent||'';
 const mobile=/Android|iPhone|iPad|iPod/i.test(agent)||(/Macintosh/i.test(agent)&&Number(self.navigator?.maxTouchPoints)>1);
 if(mobile)return `/assets/app-${role}-mobile-${/Android/i.test(agent)?'512':'1024'}-v3.png`;
 const apple=/Macintosh/i.test(agent);
 return apple?`/assets/app-${role}-mobile-1024-v3.png`:`/assets/app-${role}-512-v6.png`;
}
async function playInVisibleClient(){
 if(typeof MessageChannel==='undefined')return false;
 try{
  const tabs=await self.clients.matchAll({type:'window',includeUncontrolled:true});
  const visible=tabs.filter(tab=>tab.visibilityState==='visible'&&new URL(tab.url).origin===self.location.origin);
  const tab=visible.find(tab=>tab.focused)||visible[0];if(!tab)return false;
  return await new Promise(resolve=>{
   const channel=new MessageChannel();let settled=false;
   const finish=value=>{if(settled)return;settled=true;clearTimeout(timer);channel.port1.close();resolve(value);};
   const timer=setTimeout(()=>finish(false),350);
   channel.port1.onmessage=event=>finish(event.data?.played===true);
   try{tab.postMessage({type:'omnigo-notification-sound',deadline:Date.now()+250},[channel.port2]);}catch{finish(false);}
  });
 }catch{return false;}
}
self.addEventListener('push',event=>{
 event.waitUntil((async()=>{
  let data;try{data=event.data?.json();}catch{return;}
  if(!data||typeof data.title!=='string'||typeof data.body!=='string')return;
  const url=new URL(data.url||'/',self.location.origin);
  if(url.origin!==self.location.origin)return;
  const presentation=typeof data.presentation?.title==='string'&&typeof data.presentation?.body==='string'?data.presentation:data;
  const played=data.sound!==false&&await playInVisibleClient();
  await self.registration.showNotification((new URL(self.location.origin).hostname==='yonetim.omnigoglobal.com'?'Omnigo Global Yönetici Portalı':'Omnigo Global İş Ortağım'),{
   body:presentation.body.slice(0,500),icon:notificationIcon(),badge:'/assets/notification-badge.svg',
   tag:String(data.tag||'omnigo-order').slice(0,100),silent:data.sound===false||played,
   data:{url:url.pathname+url.search},timestamp:Date.now()
  });
 })());
});
self.addEventListener('notificationclick',event=>{
 event.notification.close();
 event.waitUntil((async()=>{
  const url=new URL(event.notification.data?.url||'/',self.location.origin);
  if(url.origin!==self.location.origin)return;
  const tabs=await self.clients.matchAll({type:'window',includeUncontrolled:true});
  const own=tabs.find(tab=>new URL(tab.url).origin===url.origin);
  if(own){
   await own.focus();
   const thread=url.searchParams.get('chatThread')||'';
   if(/^[1-9]\d{0,14}$/.test(thread)){own.postMessage({type:'omnigo-chat-open',threadId:Number(thread)});return;}
   own.postMessage({type:'omnigo-order-open',companyId:Number(url.searchParams.get('orderCompany')),view:url.searchParams.get('view'),eventType:url.searchParams.get('view')==='connections'?'integration':['order','operation','shipment','support','user'].includes(url.searchParams.get('noticeType'))?url.searchParams.get('noticeType'):'order',noticeId:Number(url.searchParams.get('noticeId'))});
  }
  else await self.clients.openWindow(url.href);
 })());
});


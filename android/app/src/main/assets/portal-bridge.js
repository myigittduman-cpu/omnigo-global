(() => {
 if (window !== window.top || location.origin !== '__OMNIGO_PORTAL_ORIGIN__') return null;
 const token='__OMNIGO_PORT_TOKEN__';
 if(window.__OMNIGO_REPORT_CONNECTION__)return window.__OMNIGO_REPORT_CONNECTION__.reconnect(token);
 if(!window.__OMNIGO_ANDROID__)Object.defineProperty(window, '__OMNIGO_ANDROID__', {value:true});
 let port, sequence=0, expectedToken=token, resolveReady;
 const pending=new Map();
 let ready=new Promise(resolve=>{resolveReady=resolve;});
 const reconnect=next=>{
  if(port)port.close();port=null;expectedToken=next;
  for(const request of pending.values())request.reject(new Error('Sayfa bağlantısı yenilendi. İşlemi yeniden başlatın.'));
  pending.clear();ready=new Promise(resolve=>{resolveReady=resolve;});
  return 'omnigo-report-ready:'+next;
 };
 Object.defineProperty(window,'__OMNIGO_REPORT_CONNECTION__',{value:{reconnect}});
  window.addEventListener('message',event=>{
   if(event.data!=='omnigo-report-port:'+expectedToken||!event.ports?.length||port)return;
   port=event.ports[0];
   port.onmessage=event=>{
    let reply;try{reply=JSON.parse(event.data);}catch{return;}
    const request=pending.get(reply.id);if(!request)return;
    pending.delete(reply.id);
    if(reply.error)request.reject(new Error(reply.error));else request.resolve(reply.status);
   };
   port.start();resolveReady();window.dispatchEvent(new Event('omnigo-report-ready'));
  });
 if(!window.__OMNIGO_LOGIN_CREDENTIALS__){
  const credentialsCall=async(operation,identity=null,password=null)=>{
   if(!['get','store','clear'].includes(operation))throw new Error('Giriş kasası işlemi geçersiz.');
   let waitTimer;
   try{await Promise.race([ready,new Promise((_,reject)=>{waitTimer=setTimeout(()=>reject(new Error('Giriş kasası hazır değil.')),5000);})]);}
   finally{clearTimeout(waitTimer);}
   return new Promise((resolve,reject)=>{
    const id=String(++sequence),timer=setTimeout(()=>{pending.delete(id);reject(new Error('Giriş kasası yanıt vermedi.'));},5000);
    pending.set(id,{resolve:value=>{clearTimeout(timer);try{resolve(JSON.parse(value));}catch{reject(new Error('Giriş kasası yanıtı geçersiz.'));}},reject:error=>{clearTimeout(timer);reject(error);}});
    port.postMessage(JSON.stringify({id,command:'credentials_'+operation,data:{identity,password}}));
   });
  };
  Object.defineProperty(window,'__OMNIGO_LOGIN_CREDENTIALS__',{value:Object.freeze({
   get:identity=>credentialsCall('get',identity),store:credential=>credentialsCall('store',credential.id,credential.password),clear:()=>credentialsCall('clear')
  })});
  window.dispatchEvent(new Event('omnigo-login-credentials-ready'));
 }
 if(__OMNIGO_PUSH_ENABLED__) {
  Object.defineProperty(window,'__OMNIGO_MOBILE_PUSH__',{value:async(command,data={})=>{
   await ready;
   return new Promise((resolve,reject)=>{
    const id=String(++sequence),timer=setTimeout(()=>{pending.delete(id);reject(new Error('Bildirim işlemi zaman aşımına uğradı.'));},60000);
    pending.set(id,{resolve:value=>{clearTimeout(timer);try{resolve(JSON.parse(value));}catch{reject(new Error('Bildirim yanıtı geçersiz.'));}},reject:error=>{clearTimeout(timer);reject(error);}});
    port.postMessage(JSON.stringify({id,command,data}));
   });
  }});
  window.dispatchEvent(new Event('omnigo-mobile-ready'));
 }
 Object.defineProperty(window,'__OMNIGO_SET_LANGUAGE__',{value:async language=>{
  if(!['tr','en','de'].includes(language))return;
  await ready;
  return new Promise((resolve,reject)=>{
   const id=String(++sequence),timer=setTimeout(()=>{pending.delete(id);reject(new Error('Language sync timed out'));},5000);
   pending.set(id,{resolve:value=>{clearTimeout(timer);resolve(value);},reject:error=>{clearTimeout(timer);reject(error);}});
   port.postMessage(JSON.stringify({id,command:'set_language',data:{language}}));
  });
 }});
 window.dispatchEvent(new Event('omnigo-native-language-ready'));
 const reportCommand=async(command,filename,contents)=>{
  if(contents.length>44739244)throw new Error('Rapor çok büyük. Daha kısa bir tarih aralığı seçin.');
  let timer;
  try{await Promise.race([ready,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Dosya kaydetme hazır değil. Sayfayı yeniden açın.')),5000);})]);}
  finally{clearTimeout(timer);}
  return new Promise((resolve,reject)=>{
   const id=String(++sequence);pending.set(id,{resolve,reject});
   try{port.postMessage(JSON.stringify({id,command,filename,contents}));}
   catch(error){pending.delete(id);reject(new Error('Dosya kaydetme bağlantısı kapandı.'));}
  });
 };
 Object.defineProperty(window,'__OMNIGO_SAVE_REPORT__',{value:(filename,contents)=>reportCommand('save_report',filename,contents)});
 Object.defineProperty(window,'__OMNIGO_SHARE_REPORT__',{value:(filename,contents)=>reportCommand('share_report',filename,contents)});
 const removeInstall=()=>document.querySelectorAll('.install-control').forEach(element=>element.remove());
 removeInstall();new MutationObserver(removeInstall).observe(document,{childList:true,subtree:true});
 // Older text reports also use detached blob links. Route only portal-generated reports to SAF.
 const originalClick=HTMLAnchorElement.prototype.click;
 HTMLAnchorElement.prototype.click=function(){
  if(!this.href.startsWith('blob:'+location.origin+'/')||!/^.+\.(txt|pdf|xlsx|jpe?g)$/.test(this.download))return originalClick.call(this);
  const url=this.href,name=this.download;
  (async()=>{
   const blob=await(await fetch(url)).blob();
   if(blob.size>33554432)throw new Error('Rapor çok büyük.');
   const encoded=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result).split(',')[1]);reader.onerror=()=>reject(new Error('Rapor okunamadı.'));reader.readAsDataURL(blob);});
   const status=await window.__OMNIGO_SAVE_REPORT__(name,encoded);
   if(status==='saved')alert('Rapor seçtiğiniz klasöre kaydedildi.');
 })().catch(error=>alert(error.message||'Rapor kaydedilemedi.'));
 };
 return 'omnigo-report-ready:'+token;
})();

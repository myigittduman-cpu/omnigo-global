import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {editRequestId} from './shipment-edit-session.js';

export function bindShipmentPresence({root,model,current,endpoint,refresh,isAdmin}){
 const events=new AbortController();
 let token=null,leaseQueue=Promise.resolve(),active=false,stopped=false,running=false,timer,dialog=null,failures=0;
 const queue=action=>(leaseQueue=leaseQueue.catch(()=>{}).then(action));
 function show(message=i18nT('Şu anda bilgiler güncelleniyor, lütfen bekleyin.')){
  model.presenceBlocked=true;
  if(!dialog){
   dialog=document.createElement('dialog');dialog.className='shipment-presence-dialog';
   dialog.setAttribute('aria-labelledby','shipmentPresenceTitle');
   dialog.innerHTML=i18nHtml('<span class="shipment-presence-symbol" aria-hidden="true">↻</span><h2 id="shipmentPresenceTitle">Bilgiler güncelleniyor</h2><p role="status" aria-live="polite"></p><small>İşlem tamamlandığında güncel liste otomatik açılacak.</small>');
   dialog.addEventListener('cancel',event=>event.preventDefault());document.body.append(dialog);dialog.showModal();
  }
  dialog.querySelector('p').textContent=message;
 }
 function hide(){model.presenceBlocked=false;if(dialog){dialog.close();dialog.remove();dialog=null;}}
 async function acquire(){
  if(!isAdmin)return;
  token??=editRequestId();const leaseToken=token;
  return queue(async()=>{if(token!==leaseToken)return;await api(endpoint('editing'),'POST',{action:'renew',token:leaseToken});if(token===leaseToken){active=true;failures=0;}});
 }
 async function release(){
  if(!isAdmin||!token)return;
  const leaseToken=token;token=null;active=false;
  try{await queue(()=>api(endpoint('editing'),'POST',{action:'release',token:leaseToken}));}catch{/* The bounded lease expires if the connection is lost. */}
 }
 async function check(){
  if(stopped||running||!current())return;
  if(isAdmin&&!active)return;
  if(!isAdmin&&document.hidden)return;
  running=true;
  try{
   if(isAdmin)await acquire();
   else{
    const data=await api(endpoint('editing'));
    if(!current())return;
    if(data.editing)show();
    else if(model.presenceBlocked){
     // An already open customer draft is preserved; version checks protect its later save.
     if(model.editor||model.draftChanges?.length){model.message=i18nT('Yönetici güncellemesi tamamlandı. Taslağınız korundu; kaydederken güncel kayıt sürümü kontrol edilecek.');const notice=root.querySelector?.('.shipment-message');if(notice)notice.textContent=model.message;hide();}
     else{const updated=await refresh({silent:true});if(model.error)throw new Error(i18nT('Liste yenilenemedi.'));if(updated!==false)hide();}
    }
   }
   failures=0;
  }catch{failures=Math.min(failures+1,3);if(!isAdmin&&model.presenceBlocked)show(i18nT('Bağlantı kontrol ediliyor. Güncel bilgiler alınır alınmaz liste açılacak.'));}
  finally{running=false;}
 }
 function schedule(){timer=setTimeout(async()=>{await check();if(!stopped)schedule();},isAdmin?15000:Math.min(10000,2000*2**failures));}
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)void check();},{signal:events.signal});
 window.addEventListener('online',()=>void check(),{signal:events.signal});
 const observer=new MutationObserver(()=>{if(!root.isConnected){stopped=true;clearTimeout(timer);events.abort();observer.disconnect();hide();void release();}});
 observer.observe(document.body,{childList:true,subtree:true});
 schedule();if(!isAdmin)void check();
 return {acquire,release,check};
}

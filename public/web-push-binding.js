// Rebind an existing OS permission to the current authenticated account.
// Permission prompts remain tied to an explicit click, never to resume events.
export async function registerWebPush({request,workerReady,current,signal,explicit=false}){
 const valid=()=>{if(signal?.aborted||!current())throw new DOMException('İşlem iptal edildi.','AbortError');};
 valid();
 let permission=Notification.permission;
 if(permission==='default'&&explicit)permission=await Notification.requestPermission();
 valid();
 if(permission!=='granted')return null;
 const config=await request('/push/config','GET',undefined,{signal});valid();
 if(!config.enabled)throw new Error('Bildirim hizmeti henüz hazır değil.');
 const registration=await workerReady();valid();
 const bytes=Uint8Array.from(atob(config.publicKey.replace(/-/g,'+').replace(/_/g,'/')),c=>c.charCodeAt(0));
 let subscription=await registration.pushManager.getSubscription();valid();
 const existing=subscription?.options?.applicationServerKey;
 if(existing&&Array.from(new Uint8Array(existing)).join()!==Array.from(bytes).join()){
  await subscription.unsubscribe();valid();subscription=null;
 }
 if(!subscription){subscription=await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:bytes});valid();}
 const ios=/iPad|iPhone|iPod/.test(navigator.userAgent)||navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1;
 const context={origin:location.origin,platform:ios?'ios':'web',standalone:Boolean(navigator.standalone||matchMedia('(display-mode: standalone)').matches)};
 const result=await request('/push/register','POST',{kind:'web',subscription:subscription.toJSON(),sound:true,clientContext:context},{signal});valid();
 return {...result,kind:'web'};
}

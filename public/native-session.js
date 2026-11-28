let restoreTask;
const bridge=()=>globalThis.window?.__OMNIGO_PERSISTENT_SESSION__;

/** The Windows host keeps the HttpOnly cookie encrypted; browser scripts never receive it. */
export function restoreNativeSession(){
 const call=bridge();
 if(!call)return Promise.resolve(false);
 return restoreTask??=Promise.resolve().then(()=>call('restore')).catch(()=>false);
}
export async function forgetNativeSession(){await bridge()?.('clear');}
export async function syncNativeSession(path,method,result){
 const route=path.split('?')[0],call=bridge();if(!call)return;
 if(route==='/account/session'&&method==='PUT'){
  const saved=await call(result.keepSession?'capture':'clear');
  if(result.keepSession&&saved!==true)throw new Error('Oturum bu cihazda kaydedilemedi. Yeniden deneyin.');
  return;
 }
 if(route==='/logout'&&method==='POST'){await call('clear');return;}
 if((route==='/me'&&method==='GET'||route==='/login'&&method==='POST')&&result?.user){
  await call('capture').catch(()=>{});
 }
}

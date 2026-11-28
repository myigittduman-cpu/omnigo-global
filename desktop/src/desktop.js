(() => {
if (window !== window.top || location.origin !== '__PORTAL_ORIGIN__' || window.__OMNIGO_SAVE_REPORT__) return;
// Runs after the webview IPC setup, on the current channel's main frame only.
Object.defineProperty(window, '__OMNIGO_DESKTOP__', { value: true });
Object.defineProperty(window, '__OMNIGO_STARTUP_GATE__', { value: true });
Object.defineProperty(window, '__OMNIGO_NATIVE_WELCOME__', { value: true });
Object.defineProperty(window,'__OMNIGO_OPEN_WEBSITE__',{value:async()=>{
 if(window!==window.top||typeof window.__TAURI_INTERNALS__?.invoke!=='function')throw new Error('Tarayıcı bağlantısı hazır değil.');
 return window.__TAURI_INTERNALS__.invoke('desktop_action',{action:'open_website'});
}});
Object.defineProperty(window,'__OMNIGO_SET_LANGUAGE__',{value:language=>{
 if(!['tr','en','de'].includes(language))return Promise.resolve();
 return window.__TAURI_INTERNALS__.invoke('desktop_action',{action:'language:'+language});
}});
window.dispatchEvent(new Event('omnigo-native-language-ready'));
Object.defineProperty(window, '__OMNIGO_DESKTOP_VERSION__', { value: '__DESKTOP_VERSION__' });
Object.defineProperty(window,'__OMNIGO_TAKE_NOTIFICATION_SETTINGS__',{value:()=>window.__TAURI_INTERNALS__.invoke('desktop_action',{action:'take_notification_settings'})});
if('__DESKTOP_PLATFORM__'==='windows'&&['https://yonetim.omnigoglobal.com','https://portal.omnigoglobal.com'].includes(location.origin)){
 Object.defineProperty(window,'__OMNIGO_PERSISTENT_SESSION__',{value:operation=>{
  if(window!==window.top||!['restore','capture','clear'].includes(operation))return Promise.reject(new Error('Oturum işlemi doğrulanamadı.'));
  return window.__TAURI_INTERNALS__.invoke('persistent_session',{operation});
 }});
 const credentialCall=(operation,identity=null,password=null)=>{
  if(window!==window.top||typeof window.__TAURI_INTERNALS__?.invoke!=='function')return Promise.reject(new Error('Windows giriş kasası hazır değil.'));
  return window.__TAURI_INTERNALS__.invoke('remembered_login',{operation,identity,password});
 };
 Object.defineProperty(window,'__OMNIGO_LOGIN_CREDENTIALS__',{value:Object.freeze({
  get:identity=>credentialCall('get',identity),
  store:credential=>credentialCall('store',credential.id,credential.password),
  clear:()=>credentialCall('clear')
 })});
}
Object.defineProperty(window, '__OMNIGO_INTEGRATION_NOTIFY__', { value: true });
Object.defineProperty(window, '__OMNIGO_RECOVERY_NOTIFY__', { value: true });
Object.defineProperty(window, '__OMNIGO_OPERATION_NOTIFY__', { value: true });
Object.defineProperty(window, '__OMNIGO_NOTICE_SCHEMA__', { value: 2 });
Object.defineProperty(window, '__OMNIGO_USER_NOTIFY__', { value: true });
Object.defineProperty(window,'__OMNIGO_BACKGROUND__',{value:async(action,data=null)=>{
 if(window!==window.top||typeof window.__TAURI_INTERNALS__?.invoke!=='function')throw new Error('Bildirim bağlantısı hazır değil.');
 return window.__TAURI_INTERNALS__.invoke('background_action',{action,data});
}});
if(location.origin==='https://yonetim.omnigoglobal.com')Object.defineProperty(window,'__OMNIGO_ORDER_NOTIFY__',{value:async details=>{
  if(window!==window.top||typeof window.__TAURI_INTERNALS__?.invoke!=='function')throw new Error('Bildirim bağlantısı hazır değil.');
  return window.__TAURI_INTERNALS__.invoke('notify_order',details);
}});
if(location.origin==='https://yonetim.omnigoglobal.com')Object.defineProperty(window,'__OMNIGO_PREVIEW_SOUND__',{value:async()=>{
  if(window!==window.top||typeof window.__TAURI_INTERNALS__?.invoke!=='function')throw new Error('Ses bağlantısı hazır değil.');
  return window.__TAURI_INTERNALS__.invoke('preview_order_sound');
}});
Object.defineProperty(window, '__OMNIGO_SAVE_REPORT__', { value: async (filename, contents) => {
  if (window !== window.top) throw new Error('Rapor yalnızca ana pencereden kaydedilebilir.');
  if (typeof window.__TAURI_INTERNALS__?.invoke !== 'function') throw new Error('Dosya kaydetme bağlantısı hazır değil. Uygulamayı kapatıp yeniden açın.');
  try { return await window.__TAURI_INTERNALS__.invoke('save_report', { filename, contents }); }
  catch (error) { throw new Error(typeof error === 'string' ? error : 'Dosya kaydedilemedi. Yeniden deneyin.'); }
} });
Object.defineProperty(window, '__OMNIGO_SHARE_REPORT__', { value: async (filename, contents) => {
 if (window !== window.top || typeof window.__TAURI_INTERNALS__?.invoke !== 'function') throw new Error('Dosya paylaşım bağlantısı hazır değil.');
 try { return await window.__TAURI_INTERNALS__.invoke('share_report', { filename, contents }); }
 catch (error) { throw new Error(typeof error === 'string' ? error : 'Dosya paylaşılamadı. Yeniden deneyin.'); }
} });
function hideInstallControl() {
  document.querySelectorAll('.install-control').forEach(button => button.remove());
}
new MutationObserver(hideInstallControl).observe(document, { childList: true, subtree: true });
document.addEventListener('DOMContentLoaded', hideInstallControl, { once: true });
hideInstallControl();
})();

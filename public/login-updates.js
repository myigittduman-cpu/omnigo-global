import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {androidRelease,androidBrowserHandoff} from './android-update-link.js';
// Login checks are isolated from active work: never reload an open shipment form.
const versionParts=value=>typeof value==='string'&&/^\d+\.\d+\.\d+$/.test(value)?value.split('.').map(Number):null;
export function newerVersion(available,current){
 const a=versionParts(available),b=versionParts(current);
 if(!a||!b)throw new Error(i18nT('Sürüm bilgisi doğrulanamadı. Yeniden deneyin.'));
 for(let i=0;i<3;i++){if(a[i]!==b[i])return a[i]>b[i];}return false;
}
export function createLoginUpdateChecker({read,desktopVersion=null,nativeDesktopUpdates=false,androidVersion=null,channel='admin',install,downloadAndroid,reload}){
 if(!['admin','client'].includes(channel))throw new Error(i18nT('Uygulama türü doğrulanamadı.'));
 // Capture the web release once, when this page starts, not on each login attempt.
 const initial=read('/version.json').then(value=>value.version).catch(()=>null);
 return {
  async check(){
   const loaded=await initial,current=await read('/version.json');
   if(!versionParts(current.version))throw new Error(i18nT('Portal sürümü okunamadı. Yeniden deneyin.'));
   // The installed Windows package owns its update channel. Store and NSIS can
   // share a version number; a web login must not force one channel onto another.
   if(desktopVersion&&!nativeDesktopUpdates){
    const release=await read(`/updates/${channel}/latest.json`);
    if(newerVersion(release.version,desktopVersion))return {kind:'desktop',version:release.version};
   }
   if(androidVersion){
    const release=await read(`/updates/android/${channel}/latest.json`);
    if(newerVersion(release.version,androidVersion)){
     const expected=androidRelease(channel,release.version).url;
     if(release.url!==expected)throw new Error(i18nT('Android güncelleme adresi doğrulanamadı.'));
     return {kind:'android',version:release.version,url:expected};
    }
   }
   if(!loaded||current.version!==loaded)return {kind:'web',version:current.version};
   return {kind:'ready',version:current.version,...(nativeDesktopUpdates?{nativeDesktopUpdates:true}:{})};
  },
  checkNativeUpdates:nativeDesktopUpdates?async()=>{
   if(typeof install!=='function')throw new Error(i18nT('Güncelleme bağlantısı hazır değil. Uygulamayı yeniden açın.'));
   await install();
  }:null,
  async apply(update){
   if(update.kind==='android'){
    if(typeof downloadAndroid!=='function')throw new Error(i18nT('Android kurulum bağlantısı hazır değil.'));
    downloadAndroid(update.url);
    throw new Error(i18nT('İndirilen APK’yı Android kurulum ekranından güncelleyin; uygulamayı silmeyin. Ardından yeniden açın.'));
   }
   if(update.kind==='desktop'){
    if(typeof install!=='function')throw new Error(i18nT('Güncelleme bağlantısı hazır değil. Uygulamayı yeniden açın.'));
    await install(); // Existing native updater verifies the signed package before installation.
    throw new Error(i18nT('Giriş için güncellemeyi tamamlayıp uygulamayı yeniden açın.'));
   }
   if(update.kind==='web')reload();
  },
 };
}
export function browserLoginUpdateChecker(channel='admin'){
 return createLoginUpdateChecker({
  channel,
  read:async path=>{const response=await fetch(path,{cache:'no-store',credentials:'omit',signal:AbortSignal.timeout(20000)});if(!response.ok)throw new Error(i18nT('Güncelleme kontrolü tamamlanamadı. Bağlantınızı kontrol edip yeniden deneyin.'));return response.json();},
  desktopVersion:window.__OMNIGO_DESKTOP__?window.__OMNIGO_DESKTOP_VERSION__:null,
  nativeDesktopUpdates:Boolean(window.__OMNIGO_DESKTOP__),
  androidVersion:/OmnigoAndroid\/[\d.]+ Store\b/.test(navigator.userAgent)?null:navigator.userAgent.match(/OmnigoAndroid\/(\d+\.\d+\.\d+)/)?.[1]||null,
  downloadAndroid:url=>location.assign(androidBrowserHandoff(channel,url)),
  install:()=>window.__TAURI_INTERNALS__?.invoke('desktop_action',{action:'check_updates'}),
  reload:()=>location.reload(),
 });
}
// A valid remembered customer session still passes through the update gate.
export function attachClientSession(form,user){
 if(!user||user.role!=='client')return null;
 const fields=['email','password'].map(name=>form.elements[name]);
 const sessionChoice=form.elements.keepSession?.closest('label');if(sessionChoice)sessionChoice.hidden=true;
 const note=document.createElement('p');note.className='admin-login-session';
 note.textContent=i18nText`${user.name} hesabıyla devam edebilirsiniz.`;
 const switchAccount=document.createElement('button');switchAccount.type='button';switchAccount.className='text-btn';switchAccount.textContent=i18nT('Farklı hesapla giriş');
 form.prepend(note,switchAccount);let active=true;
 fields.forEach(field=>{field.closest('label').hidden=true;field.required=false;});
 form.loginIdentity?.set(user.username?'username':'email',user.username||user.email);if(form.loginIdentity)form.loginIdentity.choice.hidden=true;
 function expireSession(){if(form.loginIdentity)form.loginIdentity.choice.hidden=false;active=false;note.remove();switchAccount.remove();if(sessionChoice)sessionChoice.hidden=false;fields.forEach(field=>{field.closest('label').hidden=false;field.required=true;});form.elements.password.value='';form.elements.password.focus();}
 switchAccount.onclick=expireSession;
 return {resumeAccount:()=>active?user:null,expireSession};
}
export function attachLoginUpdates(form,checker){
 const box=document.createElement('section');box.className='login-updates';box.hidden=true;box.setAttribute('aria-label',i18nT('Uygulama güncellemesi'));
 box.innerHTML=i18nHtml('<button type="button" class="btn" data-login-update>Güncellemeler</button><p role="status" aria-live="polite">Giriş sırasında güncellik kontrol edilir.</p>');
 form.before(box);
 const button=box.querySelector('button'),status=box.querySelector('[role=status]');let pending=null,busy=false;
 const show=update=>{pending=update.kind==='ready'?null:update;box.hidden=!pending;box.classList.toggle('update-required',Boolean(pending));button.textContent=pending?(pending.kind==='desktop'?i18nT('Güncelle ve yeniden başlat'):pending.kind==='android'?i18nT('Android güncellemesini indir'):i18nT('Güncelle ve yenile')):i18nT('Güncellemeler');status.textContent=pending?i18nText`Yeni sürüm (${update.version}) hazır. Giriş yapabilmek için güncelleyin.`:i18nT(update.nativeDesktopUpdates?'Portal güncel. Giriş yapabilirsiniz.':'Uygulamanız güncel. Giriş yapabilirsiniz.');};
 async function check(){if(busy)throw new Error(i18nT('Güncelleme kontrolünün tamamlanmasını bekleyin.'));busy=true;button.disabled=true;status.textContent=i18nT('Güncellemeler kontrol ediliyor…');try{const result=await checker.check();show(result);return result.kind==='ready';}catch(error){box.hidden=false;status.textContent=error.message;throw error;}finally{busy=false;button.disabled=false;}}
 button.onclick=async()=>{if(busy)return;try{if(!pending){if(checker.checkNativeUpdates){busy=true;button.disabled=true;await checker.checkNativeUpdates();busy=false;}await check();return;}busy=true;button.disabled=true;status.textContent=i18nT('Güncelleme tamamlanana kadar giriş kapalıdır.');await checker.apply(pending);}catch(error){status.textContent=error.message;}finally{busy=false;button.disabled=false;}};
 return {beforeLogin:async()=>{if(!await check())throw new Error(i18nT('Giriş için yukarıdaki güncellemeyi tamamlayın.'));}};
}



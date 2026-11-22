import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {openDialog} from './ui.js';
let pending=null;
export function confirmPortalExit(){
 if(pending)return pending;
 pending=new Promise(resolve=>{
  let confirmed=false;
  const dialog=openDialog(i18nT('Çıkmak istiyor musunuz?'),i18nHtml('<p>Oturumunuz kapatılacak ve giriş ekranına döneceksiniz.</p><div class="shipment-editor-actions"><button type="button" class="btn primary" data-confirm-exit>Evet, çıkış yap</button><button type="button" class="btn" data-cancel-exit>Vazgeç</button></div>'));
  dialog.querySelector('[data-confirm-exit]').onclick=()=>{confirmed=true;dialog.close();};
  dialog.querySelector('[data-cancel-exit]').onclick=()=>dialog.close();
  dialog.addEventListener('close',()=>resolve(confirmed),{once:true});
 }).finally(()=>{pending=null;});
 return pending;
}
export function bindAndroidBack({hasWorkspace,home,isHome,navigate}){
 window.omnigoHandleBack=()=>{
  const dialog=document.querySelector('dialog[open]');
  if(dialog){if(dialog.dispatchEvent(new Event('cancel',{cancelable:true})))dialog.close();return 'handled';}
  if(hasWorkspace()&&!isHome()){void navigate(home());return 'handled';}
  return 'exit';
 };
}

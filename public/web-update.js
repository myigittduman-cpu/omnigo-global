import {UPDATE_STORAGE_KEY,UPDATE_FINALIZATION_MS,readPendingUpdate,rememberUpdate,releaseAtLeast,readWebRelease} from './web-update-state.js';
import {updateView} from './web-update-view.js';
import {captureUpdateReturn,waitForUpdateReturn,clearUpdateReturn} from './update-return.js';

function showLoadingFrame(){
 return new Promise(resolve=>{
  const timer=setTimeout(resolve,120);
  requestAnimationFrame(()=>requestAnimationFrame(()=>{clearTimeout(timer);resolve();}));
 });
}

export function updateWorkInProgress(){
 if([...document.querySelectorAll('dialog[open]')].some(dialog=>dialog.id!=='webUpdateDialog'))return true;
 if(document.querySelector('[data-shipments]')?.shipmentUpdateBlocked?.())return true;
 // Preserve ordinary, non-dialog forms such as login and the client support editor.
 return [...document.querySelectorAll('#app form')].some(form=>[...form.elements].some(field=>{
  if(field.type==='submit'&&field.disabled)return true;
  if(field.disabled||['hidden','button','submit','reset'].includes(field.type)||field.readOnly)return false;
  if(['query','filter','category','provider','sort','month','from','to'].includes(field.name))return false;
  if(field.type==='file')return field.files?.length>0;
  if(['checkbox','radio'].includes(field.type))return field.checked!==field.defaultChecked;
  if(field.tagName==='SELECT')return [...field.options].some(option=>option.selected!==option.defaultSelected);
  return field.value!==field.defaultValue;
 }));
}
function applicationReady(){
 return new Promise((resolve,reject)=>{
  let timer;
  const clean=()=>{clearTimeout(timer);window.removeEventListener('omnigo-application-ready',done);};
  const done=()=>{clean();document.documentElement.dataset.omnigoApplicationFailed==='true'?reject(Error('Uygulama açılmadı. Bağlantınızı kontrol edip yeniden deneyin.')):resolve();};
  if(document.documentElement.dataset.omnigoApplicationReady==='true'){done();return;}
  window.addEventListener('omnigo-application-ready',done,{once:true});
  timer=setTimeout(()=>{clean();reject(Error('Güncelleme henüz yüklenemedi. Bağlantınızı kontrol edip yeniden deneyin.'));},30000);
 });
}
export async function requestWebUpdate(version){
 if(updateWorkInProgress())return false;
 const view=updateView();view.waiting();let busy=false;
 view.button.onclick=async()=>{
  if(busy)return;busy=true;view.loading();
  try{
   const latest=await readWebRelease();if(!releaseAtLeast(latest,version))throw Error('Yeni sürüm henüz erişilebilir değil. Yeniden deneyin.');
   await showLoadingFrame();
   if(updateWorkInProgress())throw Error('Açık işleminizi kaydedip veya iptal ederek tamamlayın.');
   rememberUpdate(sessionStorage,latest);captureUpdateReturn();location.reload();
  }catch(error){busy=false;view.error(error);}
 };
 return true;
}
export async function resumeWebUpdate(){
 let pending;try{pending=readPendingUpdate(sessionStorage);}catch{return false;}if(!pending)return false;
 const view=updateView();view.dialog.dataset.controllerReady='true';view.loading();
 const retry=async()=>{try{await readWebRelease();rememberUpdate(sessionStorage,pending.version);location.reload();}catch(error){view.error(error);}};
 view.button.onclick=retry;
 try{
  const [,version]=await Promise.all([applicationReady(),readWebRelease()]);
  if(!releaseAtLeast(version,pending.version))throw Error('Yeni sürüm henüz yüklenemedi. Yeniden deneyin.');
  await waitForUpdateReturn();
  // These five seconds follow verified loading; they do not predict download speed.
  await new Promise(resolve=>{const started=performance.now();const tick=()=>{const elapsed=Math.min(1,(performance.now()-started)/UPDATE_FINALIZATION_MS);view.progress(80+elapsed*20);if(elapsed<1)setTimeout(tick,40);else resolve();};tick();});
  sessionStorage.removeItem(UPDATE_STORAGE_KEY);clearUpdateReturn();view.success();await new Promise(resolve=>setTimeout(resolve,1000));view.close();return true;
 }catch(error){view.error(error);return true;}
}

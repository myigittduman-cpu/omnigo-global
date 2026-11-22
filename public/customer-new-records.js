import {api} from './api.js';
import {confirmMutation} from './action-confirmation.js';
import {html,t,text} from './i18n.js';
import {showFeedback} from './save-feedback.js';

const statusLabels={
 queued:'Yeni kayıtlar sıraya alındı.',
 running:'Yeni kayıtlar alınıyor.',
 complete:'Yeni kayıt aktarımı tamamlandı.',
 failed:'Yeni kayıt aktarımı tamamlanamadı.',
};
const inProgress=run=>run?.state==='queued'||run?.state==='running';

export function newRecordsPanel(){
 return html`<section class="store-new-records" data-store-new-records aria-label="Yeni kayıt aktarımı">
  <div class="store-new-records-actions">
   <button type="button" class="btn" data-new-records-start disabled>Yeni Kayıtları Al</button>
   <button type="button" class="text-btn" data-new-records-refresh disabled>Durumu yenile</button>
  </div>
  <p class="form-help store-new-records-status" data-new-records-status role="status">Aktarım durumu kontrol ediliyor…</p>
 </section>`;
}

export function bindNewRecordsPanel(panel,endpoint,current){
 const start=panel.querySelector('[data-new-records-start]');
 const refresh=panel.querySelector('[data-new-records-refresh]');
 const status=panel.querySelector('[data-new-records-status]');
 let run=null,busy=false;

 function showStatus(message,error=false){
  if(status.textContent!==message)status.textContent=message;
  status.classList.toggle('form-error',error);
 }

 function showRun(value){
  if(!current())return;
  run=value??null;
  if(!run){showStatus(t('Henüz yeni kayıt aktarımı istenmedi.'));return;}
  const counts=Array.isArray(run.sources)?run.sources.map(source=>source.added).filter(count=>Number.isSafeInteger(count)&&count>=0):[];
  const message=run.state==='complete'&&counts.length
   ?text`Aktarım tamamlandı. ${counts.reduce((total,count)=>total+count,0)} yeni kayıt alındı.`
   :t(statusLabels[run.state]||'Aktarım durumu alınamadı.');
  showStatus(message,run.state==='failed');
  return message;
 }

 function updateControls(){
  start.disabled=busy||inProgress(run);
  refresh.disabled=busy;
  panel.setAttribute('aria-busy',String(busy));
 }

 async function checkStatus(){
  const latest=await api(endpoint);
  showRun(latest.run);
  return latest.run;
 }

 async function request(action,notifyError=false){
  if(busy||!current())return;
  busy=true;
  updateControls();
  try{await action();}
  catch(error){if(current()&&!error.cancelled){showStatus(error.message,true);if(notifyError)showFeedback(error.message,{kind:'error'});}}
  finally{
   busy=false;
   if(current())updateControls();
  }
 }

 const load=()=>request(checkStatus);
 refresh.onclick=()=>request(async()=>{
  await checkStatus();
  if(current())showFeedback(t('Aktarım durumu güncellendi.'),{kind:'success',force:true});
 },true);
 start.onclick=()=>request(async()=>{
  if(inProgress(run))return;
  await confirmMutation(endpoint,'POST',{});
  if(!current())return;
  const result=await api(endpoint,'POST',{},{confirmed:true});
  if(!current())return;
  const value=result.run??await checkStatus();
  if(!current())return;
  if(!Object.hasOwn(statusLabels,value?.state))throw new Error(t('Aktarımın kuyruğa alındığı doğrulanamadı.'));
  const message=showRun(value);
  const kind=inProgress(value)?'pending':value.state==='complete'?'success':'error';
  showFeedback(message,{kind,force:true});
 },true);
 return load;
}

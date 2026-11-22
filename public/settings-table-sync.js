import {api} from './api.js';
import {t} from './i18n.js';
import {showFeedback} from './save-feedback.js';
import {mountPazaramaHistory} from './pazarama-history-form.js';
import {supportedModes,syncInProgress,tableSyncChoice,tableSyncList,tableSyncRun} from './settings-table-sync-view.js';
export {tableSyncPanel} from './settings-table-sync-view.js';

/** The server determines which tables, sources and replay modes a store can actually use. */
export function bindTableSync(panel,company,current,labelFor){
 const stop=new AbortController(),{signal}=stop;
 const root=panel.querySelector('.settings-table-sync'),list=root.querySelector('[data-sync-tables]');
 const status=root.querySelector('[data-sync-run]'),error=root.querySelector('[data-sync-error]'),refresh=root.querySelector('[data-sync-refresh]');
 const path='/management/new-records?company='+encodeURIComponent(company.id);
 let tables=[],run=null,target=null,busy=false,timer,request=null;
 const active=()=>!signal.aborted&&current()&&root.isConnected;
 function showError(message){error.hidden=!message;error.textContent=message||'';}
 function controls(){
  refresh.disabled=busy;
  const form=list.querySelector('[data-sync-form]');
  if(form){
   form.querySelector('[type=submit]').disabled=busy||syncInProgress(run);
   for(const radio of form.querySelectorAll('[name=syncMode]'))radio.disabled=busy;
  }
  root.setAttribute('aria-busy',String(busy));
 }
 function drawRun(){
  const markup=tableSyncRun(run,tables);
  if(status.innerHTML!==markup)status.innerHTML=markup;
 }
 function drawTargets(){
  list.innerHTML=target?tableSyncChoice(target):tableSyncList(tables);
  if(target?.key==='orders'&&target.providers?.includes('pazarama'))mountPazaramaHistory(list,company,active,signal);
  controls();
 }
 function schedule(){
  clearTimeout(timer);
  if(active()&&syncInProgress(run))timer=setTimeout(()=>void load(false,true),5000);
 }
 async function load(manual=false,statusOnly=false){
  if(!active()||busy)return;
  busy=true;controls();showError('');
  try{
   const result=await api(statusOnly?path+'&statusOnly=1':path,'GET',undefined,{signal});
   if(!active())return;
   if(!statusOnly){
    if(!Array.isArray(result.tables))throw new Error(t('Bu mağazanın aktarım seçenekleri alınamadı.'));
    const available=result.tables.map(table=>({...table,label:labelFor(table)}));
    if(JSON.stringify(tables)!==JSON.stringify(available)||!list.childElementCount){
     tables=available;target=target?tables.find(table=>table.key===target.key)??null:null;drawTargets();
    }
   }
   run=result.run??null;
   drawRun();schedule();
   if(manual)showFeedback(t('Aktarım durumu güncellendi.'),{kind:'success',force:true});
  }catch(e){if(active()){showError(e.message);if(!list.childElementCount)status.textContent=t('Aktarım durumu alınamadı.');}}
  finally{busy=false;if(active())controls();}
 }
 root.addEventListener('click',event=>{
  const button=event.target.closest('button');
  if(!button||busy||!active())return;
  if(button===refresh){void load(true);return;}
  if(button.hasAttribute('data-sync-back')){
   const key=target?.key;target=null;drawTargets();[...list.querySelectorAll('[data-sync-target]')].find(el=>el.dataset.syncTarget===key)?.focus({preventScroll:true});return;
  }
  if(!button.hasAttribute('data-sync-target'))return;
  target=tables.find(table=>table.key===button.dataset.syncTarget);
  if(!target||!supportedModes(target).length)return;
  showError('');drawTargets();list.querySelector('[name=syncMode]:checked')?.focus({preventScroll:true});
 },{signal});
 root.addEventListener('submit',async event=>{
  event.preventDefault();
  if(!active()||busy||!target||!event.target.matches('[data-sync-form]')||syncInProgress(run))return;
  const mode=new FormData(event.target).get('syncMode');
  if(!supportedModes(target).includes(mode))return;
  if(request?.table!==target.key||request?.mode!==mode)request={table:target.key,mode,requestId:crypto.randomUUID()};
  busy=true;controls();showError('');clearTimeout(timer);
  let check=false;
  try{
   const result=await api(path,'POST',request,{confirmed:true,signal});
   if(!active())return;
   if(result.run?.table!==target.key||result.run?.mode!==mode||!['queued','running','complete','failed'].includes(result.run.state))throw new Error(t('Aktarımın durumu doğrulanamadı. Durumu yenileyin.'));
   run=result.run;request=null;drawRun();schedule();
   const wording={queued:'Aktarım sıraya alındı.',running:'Aktarım sürüyor.',complete:'Aktarım tamamlandı.',failed:'Aktarım tamamlanamadı.'};
   showFeedback(t(wording[run.state]),{kind:syncInProgress(run)?'pending':run.state==='complete'?'success':'error',force:true});
  }catch(e){
   if(active()){
    if(e.status&&e.status<500)request=null;
    showError(e.status?e.message:t('İsteğin kuyruğa alındığı doğrulanamadı. Güncel durumu kontrol edin.'));
    check=e.status===409;
   }
  }finally{busy=false;if(active())controls();}
  if(check&&active()){const warning=error.textContent;await load();if(active()&&!error.textContent)showError(warning);}
 },{signal});
 void load();
 return ()=>{clearTimeout(timer);stop.abort();};
}

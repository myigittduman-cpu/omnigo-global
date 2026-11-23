import {api} from './api.js';
import {currentLocale,html,t,text} from './i18n.js';
import {esc,openDialog,date} from './ui.js';
import {loadingState,contentError,beginContentLoad} from './content-state.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {closeDialogThen} from './dialog-return.js';
import {openOperationDossier} from './operation-dossier.js';
import {openPaymentDetail} from './payment-details.js';
import {pageSnapshot} from './workspace-page-cache.js';
import {scrollTableStart,preserveTablePosition} from './table-page-scroll.js';
import {hasTableTextSelection} from './table-text-selection.js';

import {attentionTypes,attentionShell,attentionResult} from './attention-view.js';
import {attentionEditor} from './attention-editor-view.js';
export {attentionTypes,attentionStates,attentionShell,attentionResult} from './attention-view.js';
export function bindAttentionCenter(root,state,{navigate}={}){
 const host=root.querySelector('[data-attention-center]');if(!host||state.user?.role!=='admin')return;
 const token=workspaceToken(state),result=host.querySelector('[data-attention-result]'),filters=host.querySelector('form');let sequence=0,painted=null;
 const snapshot=pageSnapshot(state,'attention',['page','type','data','pendingTotal']),model={page:0,type:'all',data:null,pendingTotal:null};snapshot.restore(model);
 if(!Object.hasOwn(attentionTypes,model.type)||model.data?.items?.some(item=>item.type==='inventory')){
  Object.assign(model,{page:0,type:'all',data:null,pendingTotal:null});
 }
 filters.elements.type.value=model.type;
 host.querySelectorAll('[data-attention-type]').forEach(tab=>tab.setAttribute('aria-pressed',String(tab.dataset.attentionType===model.type)));
 const current=()=>host.isConnected&&sameWorkspace(state,token)&&state.view==='overview';
 const visible=()=>!host.closest('[role=tabpanel]')?.hidden;
 function paint(){const markup=attentionResult(model.data);if(markup===painted)return;const restore=preserveTablePosition(result);result.innerHTML=markup;painted=markup;restore();}
 function paintCount(value){
  if(!Number.isSafeInteger(value)||value<0)return;
  model.pendingTotal=value;
  root.querySelectorAll('[data-attention-count]').forEach(badge=>{
   badge.textContent=value.toLocaleString(currentLocale());
   badge.setAttribute('aria-label',text`${value} işlem bekliyor`);
   badge.hidden=false;
  });
  snapshot.save(model);
 }
 let countRequest=0;
 async function refreshCount(){
  const request=++countRequest;
  try{
   const response=await api('/operations/attention?'+new URLSearchParams({company:token.company,summary:1}));
   if(current()&&request===countRequest)paintCount(response.pendingTotal);
  }catch(error){
   if(current()&&request===countRequest&&[401,403,404].includes(error.status)){
    model.pendingTotal=null;
    root.querySelectorAll('[data-attention-count]').forEach(badge=>{badge.hidden=true;});
    snapshot.save(model);
   }
  }
 }
 if(model.data)paint();
 paintCount(model.pendingTotal);
 async function refresh({scrollToTable=false,passive=false,manual=false}={}){const blocked=()=>passive&&hasTableTextSelection(result);if(!current()||blocked())return false;if(!visible())return refreshCount();const request=++sequence,done=model.data?()=>{}:beginContentLoad(result,'Operasyon konuları hazırlanıyor…');let loaded=false;
  try{const response=await api('/operations/attention?'+new URLSearchParams({company:token.company,type:filters.elements.type.value,status:filters.elements.status.value,page:model.page}));if(!current()||request!==sequence||blocked())return false;model.data=response;model.page=response.page;model.type=filters.elements.type.value;snapshot.save(model);paint();loaded=true;
   if(model.type==='all'){countRequest++;paintCount(response.counts.open+response.counts.in_progress);}
   else void refreshCount();
   return true;
  }
  catch(e){if(current()&&request===sequence&&!model.data){result.innerHTML=contentError(e.message);painted=null;}if(manual&&current()&&request===sequence)throw e;return false;}finally{done();if(loaded&&scrollToTable)scrollTableStart(host,'.attention-table');}
 }
 filters.onsubmit=event=>event.preventDefault();filters.onchange=()=>{model.page=0;void refresh();};
 host.onclick=event=>{const button=event.target.closest('[data-attention-type],[data-attention-open],[data-attention-dismiss],[data-attention-page]');if(!button||!current())return;if(button.hasAttribute('data-attention-type')){const key=button.dataset.attentionType;if(!Object.hasOwn(attentionTypes,key))return;filters.elements.type.value=key;host.querySelectorAll('[data-attention-type]').forEach(tab=>tab.setAttribute('aria-pressed',String(tab.dataset.attentionType===key)));model.page=0;void refresh();return;}if(button.hasAttribute('data-attention-page')){model.page=Number(button.dataset.attentionPage);void refresh({scrollToTable:true});return;}const item=model.data?.items.find(row=>row.key===(button.dataset.attentionOpen||button.dataset.attentionDismiss));if(item)openItem(item,{dismiss:button.hasAttribute('data-attention-dismiss')});};
 function openItem(item,{dismiss=false}={}){
  const dialog=openDialog(t(dismiss?'Uyarı kaydını sil':'Operasyon konusu'),attentionEditor(item,{dismiss}),{className:'attention-detail-dialog'});
  const form=dialog.querySelector('form');let busy=false;const alive=()=>dialog.open&&dialog.contains(form)&&current();
  form.onsubmit=async event=>{event.preventDefault();if(busy||!alive())return false;busy=true;const error=form.querySelector('[role=alert]');error.textContent='';form.querySelector('[type=submit]').disabled=true;
   try{await api('/operations/attention?company='+token.company,'PUT',{key:item.key,type:item.type,fingerprint:item.fingerprint,version:item.version,status:'in_progress',assigneeId:null,decision:dismiss||event.submitter?.name==='dismiss'?'dismiss':form.elements.decision?.value||'',note:''});if(alive()){form.reset();dialog.close();await refresh();}return true;}
   catch(e){if(alive())error.textContent=e.message;return false;}finally{busy=false;if(alive())form.querySelector('[type=submit]').disabled=false;}
  };
  const sourceButton=form.querySelector('[data-attention-source]');if(sourceButton)sourceButton.onclick=()=>{if(busy)return;void closeDialogThen(dialog,async()=>{if(!current())return;
   if(item.source.kind==='cancellation')await navigate?.('orders');
   else if(['shipments','returns'].includes(item.type))await openOperationDossier(state,item.type==='shipments'?{kind:'shipment',id:item.source.id}:item.source);
   else if(item.type==='payments'){const payment=state.data.payments?.find(p=>p.id===item.source.id);if(payment)openPaymentDetail(payment,{state,onSave:refresh,current});else await navigate?.('payments');}
   else await navigate?.('inventory');
  });};
 }
 let timer;const schedule=()=>{timer=setTimeout(async()=>{if(!current())return;if(!document.hidden&&!document.querySelector('dialog[open]'))await refresh({passive:true});schedule();},visible()?15000:30000);};schedule();host.attentionDispose=()=>clearTimeout(timer);host.attentionRefresh=()=>refresh({passive:true});host.pageRefresh=()=>refresh({manual:true});
 void refresh();
}

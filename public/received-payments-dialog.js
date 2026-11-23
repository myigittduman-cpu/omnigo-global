import {t} from './i18n.js';
import {openDialog,toast} from './ui.js';
import {api} from './api.js';
import {confirmMutation} from './action-confirmation.js';
import {canApprovePayment} from './payment-approval.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {addDialogBack,closeDialogThen} from './dialog-return.js';
import {scrollTableStart} from './table-page-scroll.js';
import {paintContent} from './content-paint.js';
import {openPaymentDetail} from './payment-details.js';
import {manualPaymentForm} from './payment-form.js';
import {paymentReceiptForm} from './payment-receipt-form.js';
import {paymentListItems,receivedPaymentPage} from './received-payments-data.js';
import {receivedPaymentsView} from './received-payments-view.js';
import {createPaymentSelection,editablePayment} from './payment-selection.js';
import {openCalendarPaymentRemoval} from './upcoming-payment-adjustment.js';

export function receivedPaymentsDialog({getReport,state,onSave,onMarketplaceChange,current=()=>true}){
 let tab='received',panel=null,dialog=null,approving=false,filtering=false,reportScope='';
 const pages={pending:1,received:1,calendar:1};
 const token=workspaceToken(state);
 const canManage=state.user.role==='admin';
 const valid=()=>sameWorkspace(state,token)&&current()&&getReport();
 const allowed=key=>key==='pending'||key==='received'||(canManage&&key==='calendar');
 const rows=()=>valid()?paymentListItems(getReport(),tab):[];
 const selection=canManage?createPaymentSelection({
  state,rows,current:()=>!filtering&&valid()&&dialog?.open&&dialog.classList.contains('received-payments-dialog'),
  render:()=>refresh(),
  onDeleted:async ids=>{
   const deleted=new Set(ids),report=getReport();
   report.items=report.items.filter(item=>!deleted.has(item.id));
   for(const field of ['items','periodItems']){
    if(report.upcoming?.[field])report.upcoming[field]=report.upcoming[field].filter(item=>item.source!=='manual'||!deleted.has(item.id));
   }
   refresh();
   await onSave();
  },
 }):null;
 const syncScope=()=>{
  const {from,to,channel='all'}=getReport();
  const scope=JSON.stringify([from,to,channel]);
  if(scope===reportScope)return;
  reportScope=scope;selection?.reset();
  for(const key of Object.keys(pages))pages[key]=1;
 };
 const refresh=()=>{
  if(!valid()||!dialog?.open||!dialog.classList.contains('received-payments-dialog'))return;
  syncScope();
  selection?.sync();
  pages[tab]=receivedPaymentPage(getReport(),pages[tab],tab).page;
  paintContent(panel,receivedPaymentsView(getReport(),pages[tab],canManage,tab,selection));
 };
 const afterSave=async()=>{await onSave();if(valid())open();};
 function open(selectedTab){
  if(!valid())return;
  if(allowed(selectedTab)){selection?.reset();tab=selectedTab;}
  syncScope();
  pages[tab]=receivedPaymentPage(getReport(),pages[tab],tab).page;
  dialog=openDialog(t('Ödeme detayları'),receivedPaymentsView(getReport(),pages[tab],canManage,tab,selection),{className:'received-payments-dialog'});
  panel=dialog.querySelector('.dialog-body');
  selection?.bind(panel);
  panel.addEventListener('change',async event=>{
   const filter=event.target;
   if(!filter.matches('[data-payment-marketplace]')||!valid()||filtering||approving||selection?.busy)return;
   filter.disabled=true;filtering=true;
   try{await onMarketplaceChange?.(filter.value);}
   finally{filtering=false;if(filter.isConnected){filter.value=getReport()?.channel||'all';filter.disabled=false;}}
  });
  panel.addEventListener('click',async event=>{
   const button=event.target.closest('button');if(!button||!valid()||approving||filtering||selection?.busy)return;
   if(button.hasAttribute('data-payment-list-tab')){
    if(!allowed(button.dataset.paymentListTab)||tab===button.dataset.paymentListTab)return;
    selection?.reset();tab=button.dataset.paymentListTab;refresh();
    panel.querySelector(`[data-payment-list-tab="${tab}"]`)?.focus({preventScroll:true});scrollTableStart(panel,'table');return;
   }
   if(button.hasAttribute('data-received-page')){
    pages[tab]=receivedPaymentPage(getReport(),button.dataset.receivedPage,tab).page;refresh();
    panel.querySelector('[aria-current="page"]')?.focus({preventScroll:true});scrollTableStart(panel,'table');return;
   }
   if(canManage&&button.hasAttribute('data-received-remove')){
    const row=rows().find(item=>String(item.id)===button.dataset.receivedRemove);
    if(row)await closeDialogThen(dialog,()=>{
     const child=openCalendarPaymentRemoval(row,{state,current:valid,onSave:afterSave});
     if(child)addDialogBack(child,open,'Ödeme detaylarına dön');else open();
    });
    return;
   }
   const id=Number(button.dataset.receivedDetail||button.dataset.receivedReceipt||button.dataset.receivedEdit||button.dataset.receivedApprove);
   const record=rows().find(item=>Number(item.id)===id);if(!record)return;
   if(button.hasAttribute('data-received-approve')){await approve(record);return;}
   if(button.hasAttribute('data-received-edit')){await closeDialogThen(dialog,()=>openEditor(record));return;}
   await closeDialogThen(dialog,()=>{
    if(!valid())return;
    let child;
    if(button.hasAttribute('data-received-detail')){
     child=openPaymentDetail(record,{state,onSave:afterSave,current:valid});
     if(canManage&&editablePayment(record)){const edit=document.createElement('button');edit.type='button';edit.className='btn';edit.textContent=t('Düzenle');edit.onclick=()=>closeDialogThen(child,()=>openEditor(record));child.querySelector('.dialog-body').append(edit);}
    }else if(canManage)child=paymentReceiptForm(record,state,afterSave);
    if(child)addDialogBack(child,open,'Ödeme detaylarına dön');
   });
  });
 }
 async function approve(record){
  if(!canApprovePayment(record,state)||approving)return;
  approving=true;const path=`/records/payments/${record.id}/approve?company=${token.company}`;
  try{
   await confirmMutation(path,'POST',{version:record.version});
   if(!valid()||!dialog?.open)return;
   const updated=await api(path,'POST',{version:record.version},{confirmed:true});
   if(!valid())return;
   // Reflect the committed result immediately, without reopening the parent dialog.
   Object.assign(record,updated);refresh();
   try{await onSave();if(valid())refresh();}
   catch{toast(t('Ödeme onaylandı. Listeyi yeniden açın; onay işlemini tekrar etmeyin.'));}
  }catch(error){if(!error.cancelled&&valid())toast(error.message);}
  finally{approving=false;}
 }
 function openEditor(record){if(!valid()||!canManage||!editablePayment(record))return;const child=manualPaymentForm(record,state,afterSave);if(child)addDialogBack(child,open,'Ödeme detaylarına dön');}
 return {open,refresh};
}

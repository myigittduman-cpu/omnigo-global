import {date,openDialog} from './ui.js';
import {validCalendarDate} from './calendar-date.js';
import {manualPaymentForm} from './payment-form.js';
import {paymentReceiptForm} from './payment-receipt-form.js';
import {openPaymentDetail} from './payment-details.js';
import {addDialogBack,closeDialogThen} from './dialog-return.js';
import {paymentDayItems,paymentDayPage,paymentDayView} from './payment-day-view.js';
import {createPaymentSelection,editablePayment} from './payment-selection.js';

export function openPaymentDay({day,report,getReport=()=>report,state,onSave,current=()=>true}){
 if(!current()||!validCalendarDate(day)||day<report.from||day>report.to)return;
 let rows=paymentDayItems(report,day),page=1,dialog,panel;
 const canManage=state.user.role==='admin';
 const active=()=>dialog?.open&&dialog.classList.contains('payment-day-dialog')&&current();
 async function reload(){
  await onSave();
  const latest=getReport();
  if(current()&&latest)rows=paymentDayItems(latest,day);
 }
 const afterSave=async()=>{await reload();selection?.reset();if(current())open();};
 const selection=canManage?createPaymentSelection({
  state,rows:()=>rows,current:active,render,
  onDeleted:async ids=>{
   rows=rows.filter(row=>!ids.includes(row.id));
   render();await reload();render();
  },
 }):null;
 function render(){
  if(!active())return;
  selection?.sync();
  page=paymentDayPage(rows,page).page;
  const left=panel.querySelector('.payment-day-table-wrap')?.scrollLeft||0;
  panel.innerHTML=paymentDayView(rows,canManage,selection,page);
  const table=panel.querySelector('.payment-day-table-wrap');if(table)table.scrollLeft=left;
 }
 function showChild(create){
  if(!active())return;
  void closeDialogThen(dialog,()=>{
   if(!current())return;
   const child=create();
   if(child)addDialogBack(child,open,'Günün hakedişlerine dön');else open();
  });
 }
 function open(){
  if(!current())return;
  page=paymentDayPage(rows,page).page;
  dialog=openDialog(date(day)+' · Hakediş detayları',paymentDayView(rows,canManage,selection,page),{className:'payment-day-dialog'});
  panel=dialog.querySelector('.dialog-body');
  selection?.bind(panel);
  panel.addEventListener('click',event=>{
   const button=event.target.closest('button');if(!button||!active()||selection?.busy)return;
   if(button.hasAttribute('data-day-payment-page')){
    page=paymentDayPage(rows,button.dataset.dayPaymentPage).page;render();
    panel.querySelector('[aria-current=page]')?.focus({preventScroll:true});return;
   }
   if(canManage&&button.hasAttribute('data-day-payment-add')){
    const latest=getReport();
    showChild(()=>manualPaymentForm(null,state,afterSave,{expectedDate:day,preferredChannel:latest?.channel,warnings:latest?.upcoming?.sourceWarnings}));return;
   }
   const record=rows.find(item=>item.id===Number(button.dataset.dayPaymentDetail||button.dataset.dayPaymentEdit||button.dataset.dayPaymentReceipt));
   if(!record)return;
   if(button.hasAttribute('data-day-payment-detail')){showChild(()=>openPaymentDetail(record,{state,onSave:afterSave,current}));return;}
   if(!canManage||!editablePayment(record))return;
   if(button.hasAttribute('data-day-payment-edit'))showChild(()=>manualPaymentForm(record,state,afterSave,{warnings:getReport()?.upcoming?.sourceWarnings}));
   if(button.hasAttribute('data-day-payment-receipt'))showChild(()=>paymentReceiptForm(record,state,afterSave));
  });
 }
 open();
}

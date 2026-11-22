import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {confirmPendingChanges} from './pending-changes.js';

export const editRequestId=()=>Array.from(crypto.getRandomValues(new Uint8Array(24)),value=>value.toString(16).padStart(2,'0')).join('');
export function stageShipmentChange(model,change,label){
 model.draftChanges??=[];
 const key=change.type==='create'?change.requestId:String(change.id);
 const index=model.draftChanges.findIndex(item=>item.key===key),item={key,change,label};
 if(index<0)model.draftChanges.push(item);else model.draftChanges[index]=item;
 model.draftLog??=[];model.draftLog.push(label);model.commitRequest=null;
}
export function shipmentDraftRows(model){
 const rows=(model.data?.items||[]).filter(row=>!(model.draftChanges||[]).some(item=>item.change.id===row.id&&item.change.type==='delete')).map(row=>{
  const update=model.draftChanges?.find(item=>item.change.id===row.id&&item.change.type==='update');
  if(!update)return row;
  const record=update.change.record;
  return {...row,...record,orderedAt:record.orderedAt+'+03:00',invoiceCents:record.invoiceAmount===''?null:Math.round(Number(record.invoiceAmount.replace(',','.'))*100),draft:true};
 });
 const created=(model.draftChanges||[]).flatMap((item,index)=>{
  if(item.change.type!=='create')return [];
  const record=item.change.record;
  return [{...record,id:-index-1,provider:model.provider,orderedAt:record.orderedAt+'+03:00',invoiceCents:record.invoiceAmount===''?null:Math.round(Number(record.invoiceAmount.replace(',','.'))*100),draft:true,draftKey:item.key}];
 });
 return [...created,...rows];
}
export function bindShipmentEditSession({root,model,current,endpoint,paintTable,refresh,invalidate,presence,companyName=''}){
 model.draftChanges=[];model.draftLog=[];
 let leaveDialog=null,bypass=false;
 const message=value=>{model.message=value;paintTable();};
 const clear=async()=>{model.editor=null;model.draftChanges=[];model.draftLog=[];model.commitRequest=null;model.editMode=false;await presence?.release();};
 async function save({confirmed=false}={}){
  if(model.editor){message(i18nT('Önce açık formdaki “Değişikliği ekle” düğmesine basın veya formdan vazgeçin.'));return false;}
  if(!model.draftChanges.length){await clear();paintTable();return true;}
  if(model.writing)return false;
  model.commitRequest??={requestId:editRequestId(),changes:model.draftChanges.map(item=>item.change)};
  model.writing=true;invalidate();paintTable();
  try{
   await presence?.acquire();
   const result=await api(endpoint('commit'),'POST',model.commitRequest,{confirmed});
   if(!current())return false;
   await clear();model.message=i18nText`${result.saved} işlem kaydedildi.`;return true;
  }catch(error){if(current())model.message=error.message;return false;}
  finally{model.writing=false;if(current()){if(model.editMode)paintTable();else await refresh();}}
 }
 async function canLeave({target=''}={}){
  if(!current())return true;
  if(model.writing){message(i18nT('Kayıt işlemi tamamlanana kadar bekleyin.'));return false;}
  if(!model.editor&&!model.draftChanges.length){await clear();return true;}
  if(leaveDialog)return leaveDialog;
  leaveDialog=confirmPendingChanges({source:companyName,target,current,
   changes:[...model.draftChanges.map(item=>item.label),...(model.editor?[i18nText`${model.editor.values.customerName||i18nT('Yeni sipariş')} · ${model.editor.values.productName||i18nT('Ürün bilgisi')}: açık sevkiyat formu`]:[])],
   save:async()=>{
    if(model.editor){const form=root.querySelector('[data-shipment-editor]');if(!form?.checkValidity())throw new Error(i18nT('Açık sevkiyat formunda eksik veya geçersiz alanlar var. Vazgeç ile forma dönüp alanları tamamlayın.'));form.requestSubmit();if(model.editor)throw new Error(i18nT('Açık form taslağa eklenemedi. Bilgilerinizi kontrol edin.'));}
    if(!await save({confirmed:true}))throw new Error(model.message||i18nT('Kayıt tamamlanamadı.'));return true;
   },
   discard:async()=>{await clear();paintTable();return true;}
  }).finally(()=>{leaveDialog=null;});
  return leaveDialog;
 }
 root.shipmentCanLeave=canLeave;
 // Passive update guard: never cancel, save or discard an active shipment session.
 root.shipmentUpdateBlocked=()=>current()&&Boolean(model.editMode||model.editor||model.writing||model.draftChanges?.length||model.saving?.size);
 const events=new AbortController(),{signal}=events;
 window.addEventListener('beforeunload',event=>{if(current()&&(model.editor||model.draftChanges.length||model.writing)){event.preventDefault();event.returnValue='';}},{signal});
 document.addEventListener('click',async event=>{
  const link=event.target.closest('[data-nav],#logout,[data-more-menu]:not([aria-controls="workspace-directory"]),[data-workspace-switch],a[href]');
  if(!link||bypass||!current()||(!model.editor&&!model.draftChanges.length&&!model.writing))return;
  event.preventDefault();event.stopImmediatePropagation();
  if(await canLeave()){bypass=true;try{link.click();}finally{bypass=false;}}
 },{capture:true,signal});
 const observer=new MutationObserver(()=>{if(!root.isConnected){events.abort();observer.disconnect();}});
 observer.observe(document.body,{childList:true,subtree:true});
 return {canLeave,save,async click(button){
  if(button.hasAttribute('data-shipment-edit-mode')){try{await presence?.acquire();if(current()){model.editMode=true;model.message='';paintTable();}else await presence?.release();}catch(error){message(error.message);}return true;}
  if(button.hasAttribute('data-shipment-session-save')){await save();return true;}
  if(button.hasAttribute('data-shipment-session-cancel')){if(await canLeave())await refresh();return true;}
  if(button.hasAttribute('data-shipment-delete')){
   if(!model.editMode||model.editor||model.canDelete===false)return true;
   const row=shipmentDraftRows(model).find(item=>item.id===Number(button.dataset.shipmentDelete));if(!row)return true;
   if(row.draftKey){model.draftChanges=model.draftChanges.filter(item=>item.key!==row.draftKey);model.draftLog.push(i18nText`${row.customerName} · ${row.productName}: taslak ekleme kaldırıldı`);model.commitRequest=null;paintTable();return true;}
   stageShipmentChange(model,{type:'delete',id:row.id,version:row.version},`${row.customerName} · ${row.productName}: silinecek`);
   model.selectedIds?.delete(row.id);message(i18nT('Silme işlemi taslağa eklendi. Kesinleştirmek için Kaydet’e basın.'));return true;
  }
  return false;
 }};
}

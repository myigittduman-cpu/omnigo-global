import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {editorFields} from './shipment-editor-view.js';
import {editRequestId,stageShipmentChange,shipmentDraftRows} from './shipment-edit-session.js';
import {bindMoneyInputs} from './money-inputs.js';
import {canonicalMoneyEntry} from './money-value.js';
const localDate=value=>new Date(value).toLocaleString('sv-SE',{timeZone:'Europe/Istanbul'}).replace(' ','T');
export function bindShipmentEditor({root,model,current,paintTable,invalidate,session}){
 bindMoneyInputs(root);
 root.addEventListener('input',event=>{if(event.target.closest('[data-shipment-editor]')&&model.editor)model.editor.values[event.target.name]=event.target.value;});
 root.addEventListener('change',event=>{if(event.target.closest('[data-shipment-editor]')&&model.editor)model.editor.values[event.target.name]=event.target.value;});
 root.addEventListener('submit',event=>{
  if(!event.target.matches('[data-shipment-editor]'))return;event.preventDefault();event.stopImmediatePropagation();
  const editor=model.editor;if(!current()||!editor||model.writing)return;
  editor.values=Object.fromEntries(new FormData(event.target));
  editor.values.invoiceAmount=canonicalMoneyEntry(editor.values.invoiceAmount);
  const change=editor.id>0?{type:'update',id:editor.id,version:editor.version,record:editor.values}:{type:'create',requestId:editor.requestId,record:editor.values};
  stageShipmentChange(model,change,`${editor.values.customerName} · ${editor.values.productName}: ${editor.id?i18nT('düzenlenecek'):'eklenecek'}`);
  model.editor=null;model.message=i18nT('Değişiklik taslağa eklendi. Kesinleştirmek için üstteki Kaydet’e basın.');paintTable();
 });
 return {canLeave:session.canLeave,async click(button){
  if(button.hasAttribute('data-shipment-edit-cancel')){model.editor=null;paintTable();return true;}
  if(button.closest('[data-shipment-editor]'))return true;
  if(button.hasAttribute('data-shipment-add')&&model.provider==='all'){
   model.addWarning=i18nT('Lütfen önce bir pazaryeri seçiniz.');paintTable();return true;
  }
  if(button.hasAttribute('data-shipment-add')||button.hasAttribute('data-shipment-edit-row')){
   if(!model.editMode||model.editor||model.busy||model.writing)return true;
   if(button.hasAttribute('data-shipment-add')&&!model.canCreate)return true;
   const row=button.hasAttribute('data-shipment-edit-row')?shipmentDraftRows(model).find(item=>item.id===Number(button.dataset.shipmentEditRow)):null;
   if(button.hasAttribute('data-shipment-edit-row')&&(!row||model.canEdit===false))return true;
   const staged=model.draftChanges.find(item=>item.change.id===row?.id&&item.change.type==='update')?.change;
   const now=localDate(Date.now()),values=Object.fromEntries(editorFields.map(([key])=>[key,String(row?.[key]??'')]));
   values.orderedAt=row?localDate(row.orderedAt):(now.startsWith(model.month)?now:model.month+'-01T09:00:00');
   values.invoiceAmount=row&&Number.isSafeInteger(row.invoiceCents)?(row.invoiceCents/100).toFixed(2).replace('.',','):'';
   values.quantity=String(row?.quantity??1);values.province=row?.province??(model.city==='all'?'':model.city);values.customerType=row?.customerType??'Bireysel';
   invalidate();model.busy=false;model.editor={id:row?.id,version:row?.version,values:staged?{...staged.record}:values,requestId:row?.draftKey||editRequestId(),saving:false,message:''};model.message='';paintTable();
   const form=root.querySelector('[data-shipment-editor]');form.scrollIntoView({block:'nearest',behavior:'smooth'});form.querySelector('input').focus({preventScroll:true});return true;
  }
  return false;
 }};
}

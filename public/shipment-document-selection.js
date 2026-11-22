import {api} from './api.js';
import {confirmAction} from './action-confirmation.js';
import {currentLocale,html,t,text} from './i18n.js';
import {toast} from './ui.js';

export function shipmentDocumentActions(){
 return html`<div class="shipment-tracking-actions" aria-label="Seçilen belge kayıtları">
  <span data-tracking-selection-count aria-live="polite">0 kayıt seçildi</span>
  <button type="button" class="btn" data-tracking-action="deliver" disabled>Teslim Edildi</button>
  <button type="button" class="btn" data-tracking-action="remove" disabled>Listeden sil</button>
 </div>`;
}

export function bindTrackingSelection(root,{model,current,company,kind,refresh,invalidate}){
 const selectedRows=()=>(model.data?.items??[]).filter(row=>model.selectedIds.has(row.id));
 const count=root.querySelector('[data-tracking-selection-count]');
 const deliver=root.querySelector('[data-tracking-action="deliver"]');
 const remove=root.querySelector('[data-tracking-action="remove"]');
 const selections=()=>[...root.querySelectorAll('[data-tracking-select]')];
 const selectAll=()=>root.querySelector('[data-tracking-select-all]');

 function update(){
  const rows=selectedRows(),checkboxes=selections(),all=selectAll();
  count.textContent=text`${rows.length.toLocaleString(currentLocale())} kayıt seçildi`;
  deliver.disabled=model.writing||!rows.length||rows.some(row=>row.canMarkDelivered===false)||rows.every(row=>row.delivered);
  remove.disabled=model.writing||!rows.length;
  for(const checkbox of checkboxes){
   checkbox.checked=model.selectedIds.has(Number(checkbox.dataset.trackingSelect));
   checkbox.disabled=model.writing;
  }
  if(all){
   all.checked=checkboxes.length>0&&rows.length===checkboxes.length;
   all.indeterminate=rows.length>0&&!all.checked;
   all.disabled=model.writing||!checkboxes.length;
  }
 }

 root.addEventListener('change',event=>{
  const input=event.target;
  if(!current()||model.writing)return;
  if(input.matches('[data-tracking-select-all]')){
   model.selectedIds.clear();
   if(input.checked)for(const row of model.data?.items??[])model.selectedIds.add(row.id);
  }else if(input.matches('[data-tracking-select]')){
   const id=Number(input.dataset.trackingSelect);
   if(input.checked)model.selectedIds.add(id);
   else model.selectedIds.delete(id);
  }else return;
  update();
 });

 root.addEventListener('click',async event=>{
  const button=event.target.closest('[data-tracking-action]');
  if(!button||button.disabled||model.writing||!current())return;
  const action=button.dataset.trackingAction,rows=selectedRows(),bucket=model.queue;
  const removing=action==='remove';
  const message=removing
   ?text`${rows.length} kaydı bu belge listesinden kaldırmak istiyor musunuz?`
   :text`${rows.length} kaydı belge takibinde teslim edildi olarak işaretlemek istiyor musunuz?`;
  invalidate();model.writing=true;update();
  let reload=false;
  try{
   const confirmed=await confirmAction(message,{
    heading:t(removing?'Listeden sil':'Teslim Edildi'),approveLabel:t(removing?'Listeden sil':'Evet, onaylıyorum'),
   });
   if(!confirmed||!current())return;
   await api('/shipments/document-queue/actions?'+new URLSearchParams({company}),'POST',{
    kind,bucket,action,items:rows.map(({id,version})=>({id,version})),
   },{confirmed:true});
   if(current())toast(t(removing?'Seçilen kayıtlar listeden kaldırıldı.':'Seçilen kayıtlar teslim edildi olarak işaretlendi.'));
   reload=true;
  }catch(error){
   if(current())toast(error.message);
   reload=error.status===409;
  }finally{
   model.writing=false;
   if(current()){
    if(reload)model.selectedIds.clear();
    await (reload?refresh():Promise.resolve());
    if(current())update();
   }
  }
 });
 return {update};
}

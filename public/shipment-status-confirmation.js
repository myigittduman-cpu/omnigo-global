import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {esc,openDialog} from './ui.js';

export function confirmShipmentInvoice(row,value){
 const title=i18nT(value?'Fatura onayı':'Fatura işaretini geri al');
 const question=i18nT(value?'Fatura Düzenleme İşlemi Tamamlandı mı?':'Fatura işaretini geri almak istiyor musunuz?');
 return new Promise(resolve=>{
  const dialog=openDialog(title,i18nHtml`
   <p class="shipment-invoice-question" id="shipmentInvoiceQuestion">${esc(question)}</p>
   ${row.orderNumber?i18nHtml`<div class="shipment-invoice-order">
    <span>Sipariş numarası</span><strong>${esc(row.orderNumber)}</strong>
   </div>`:''}
   <div class="dialog-actions">
    <button type="button" class="btn" data-invoice-no autofocus>Hayır</button>
    <button type="button" class="btn primary" data-invoice-yes>Evet</button>
   </div>`,{className:'shipment-invoice-confirm-dialog'});
  let accepted=false;
  dialog.setAttribute('aria-describedby','shipmentInvoiceQuestion');
  dialog.querySelector('[data-invoice-no]').onclick=()=>dialog.close();
  dialog.querySelector('[data-invoice-yes]').onclick=()=>{accepted=true;dialog.close();};
  dialog.addEventListener('close',()=>{
   if(dialog.getAttribute('aria-describedby')==='shipmentInvoiceQuestion')dialog.removeAttribute('aria-describedby');
   resolve(accepted);
  },{once:true});
 });
}

export async function confirmShipmentStatus({endpoint,rows,value,current}){
 const preview=await api(endpoint('status-preview'),'POST',{ids:rows.map(row=>row.id),value});
 if(!current())return null;
 return new Promise(resolve=>{
  const dialog=openDialog(i18nT('Sipariş durumunu değiştirmek istiyor musunuz?'),i18nHtml`
   <p>Pazaryerinden son alınan durum ile seçtiğiniz yeni durumu kontrol edin.</p>
   <div class="shipment-status-comparison">${preview.items.map(row=>i18nHtml`<section><strong>${esc(row.customerName)} · ${esc(row.provider)}</strong><p>${esc(row.productName)}</p><dl><dt>Pazaryerindeki durum</dt><dd>${esc(row.marketplaceStatusText)}</dd><dt>Uygulamadaki durum</dt><dd>${esc(row.currentStatusText)}</dd><dt>Getirmek istediğiniz durum</dt><dd><strong>${esc(preview.targetStatusText)}</strong></dd></dl></section>`).join('')}</div>
   <p>Onaylanan durum yalnız uygulamada değişir ve siz tekrar değiştirene kadar korunur.</p>
   <div class="shipment-editor-actions"><button type="button" class="btn primary" data-confirm-status>Evet, değiştir</button><button type="button" class="btn" data-cancel-status>Vazgeç</button></div>`);
  let accepted=false;
  dialog.querySelector('[data-confirm-status]').onclick=()=>{accepted=true;dialog.close();};
  dialog.querySelector('[data-cancel-status]').onclick=()=>dialog.close();
  dialog.addEventListener('close',()=>resolve(accepted&&current()?preview:null),{once:true});
 });
}

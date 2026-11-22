import {shipmentDeliveryAddressText} from './shipment-address-display.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {esc} from './ui.js';
import {fulfillmentNote} from './shipment-fulfillment.js';
import {cancellationNotice} from './order-cancellation.js';
import {shipmentStatusControls,shipmentStatusLabel} from './shipment-status.js';
import {shipmentDocumentControls} from './shipment-documents.js';
import {shipmentTableAmountCents} from './table-amounts.js';
import {followShipmentMenu} from './shipment-menu-position.js';

export function shipmentOperations(row,{selected=false,canConfirm=false,open=false,readOnly=false,showCancellations=true}={}){
 if(readOnly)return i18nHtml`${cancellationNotice(row)}<small class="shipment-operation-status status-badge status-${esc(row.marketplaceReturn?.returnedQuantity>0?'cancelled':(row.displayRowStatus??row.rowStatus)||'pending')}">${esc(shipmentStatusLabel(row))}</small>${row.dispatchTracked?i18nHtml`<small class="shipment-marketplace-status">Pazaryeri: ${esc(row.marketplaceStatusText||i18nT('Bilgi yok'))}</small>`:''}`;
 const id=Number(row.id),name=esc(row.customerName||'Sipariş '+id),amount=shipmentTableAmountCents(row);
 return i18nHtml`${cancellationNotice(row)}<details class="shipment-operations" data-shipment-operations="${id}" ${open?'open':''}>
 <summary aria-label="${name} · Sipariş işlemleri"><span>İşlemler</span><svg viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 7 5 5 5-5"/></svg></summary>
 <div class="shipment-operations-panel"><header><strong>Sipariş işlemleri</strong><button type="button" data-shipment-operations-close aria-label="Sipariş işlemlerini kapat">×</button></header>
 <p class="shipment-operations-source">${esc(row.provider||i18nT('Pazaryeri belirtilmemiş'))} · ${name}</p>
 ${row.orderNumber?i18nHtml`<p class="shipment-operations-source">Sipariş: ${esc(row.orderNumber)}</p>`:''}${(row.dataIssues||[]).length?i18nHtml`<p class="shipment-data-warning">${row.dataIssues.map(esc).join(' · ')}</p>`:''}${fulfillmentNote(row,canConfirm)}${canConfirm?shipmentStatusControls(row,showCancellations):''}${shipmentDocumentControls(row)}<div class="shipment-mobile-details"><strong>Sipariş ayrıntıları</strong><dl>${[[row.customerNameSource==='recipient'?i18nT('Teslimat alıcısı'):i18nT('Müşteri'),row.customerName],[i18nT('Telefon'),row.phone],[i18nT('Teslimat adresi'),shipmentDeliveryAddressText(row)],[i18nT('Fatura tutarı'),amount==null?'—':(amount/100).toLocaleString(currentLocale())+' TL'],[i18nT('Fatura adı'),row.invoiceName],[i18nT('Fatura adresi'),row.invoiceAddress],[i18nT('E-posta'),row.recipientEmail],[i18nT('Müşteri tipi'),row.customerType]].map(([label,value])=>i18nHtml`<dt>${label}</dt><dd>${esc(value||'—')}</dd>`).join('')}</dl></div>
 </div></details>
 <small class="shipment-operation-status status-badge status-${esc(row.marketplaceReturn?.returnedQuantity>0?'cancelled':(row.displayRowStatus??row.rowStatus)||'pending')}">${esc(shipmentStatusLabel(row))}</small>${row.dispatchTracked?i18nHtml`<small class="shipment-marketplace-status">Pazaryeri: ${esc(row.marketplaceStatusText||i18nT('Bilgi yok'))}</small>`:''}`;
}

const menuSelector='details:is([data-shipment-operations],[data-shipment-product-name])';
const openedMenu=menuSelector+'[open]';
export function bindShipmentOperations(root){
 let activeMenu=null;
 let stopFollowing=()=>{};
 function follow(menu){
  if(activeMenu===menu)return;
  root.querySelectorAll(openedMenu).forEach(other=>{if(other!==menu)other.open=false;});
  stopFollowing();
  activeMenu=menu;
  stopFollowing=followShipmentMenu(menu);
 }
 root.addEventListener('toggle',event=>{
  const menu=event.target;
  if(!menu.matches?.(menuSelector))return;
  if(menu.open)follow(menu);
  else if(activeMenu===menu){stopFollowing();activeMenu=null;}
 },true);
 root.addEventListener('click',event=>{
  const button=event.target.closest('[data-shipment-operations-close]');if(!button)return;
  const menu=button.closest('details');menu.open=false;menu.querySelector('summary').focus({preventScroll:true});
 });
 root.addEventListener('keydown',event=>{
  if(event.key!=='Escape')return;const menu=event.target.closest(openedMenu);if(!menu)return;
  event.preventDefault();menu.open=false;menu.querySelector('summary').focus({preventScroll:true});
 });
 return {
  close:()=>{if(activeMenu){activeMenu.open=false;stopFollowing();activeMenu=null;}},
  capture:()=>{
   const menu=root.querySelector(openedMenu);
   if(!menu)return null;
   return menu.hasAttribute('data-shipment-product-name')
    ?{attribute:'data-shipment-product-name',id:menu.dataset.shipmentProductName}
    :{attribute:'data-shipment-operations',id:menu.dataset.shipmentOperations};
  },
  restore:opened=>{
   if(!opened)return;
   const menu=root.querySelector(`[${opened.attribute}="${CSS.escape(opened.id)}"]`);
   if(!menu)return;
   menu.open=true;
   follow(menu);
  },
 };
}

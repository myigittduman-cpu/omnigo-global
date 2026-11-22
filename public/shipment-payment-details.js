import {html,t} from './i18n.js';
import {esc,money} from './ui.js';

const labels={
 productCents:'Ürün tutarı',
 sellerDiscountCents:'Mağaza indirimi',
 platformDiscountCents:'Pazaryeri indirimi',
 discountCents:'Toplam indirim',
 couponCents:'Pazaryeri kuponu',
 shippingCents:'Kargo tutarı',
 installmentCents:'Vade farkı',
 netProductCents:'İndirim sonrası ürün tutarı',
 buyerCents:'Tahsilat tutarı',
 merchantCents:'Satıcı tutarı',
 totalCents:'Pazaryeri toplamı',
};

export function shipmentPaymentDetails(items){
 const lines=[];
 for(const [key,label] of Object.entries(labels)){
  const values=items.map(row=>row.marketplaceAmounts?.[key]);
  if(!values.length||!values.every(value=>Number.isSafeInteger(value)&&value>=0))continue;
  const amount=values.reduce((total,value)=>total+value,0);
  if(!Number.isSafeInteger(amount)||(!amount&&key!=='productCents'))continue;
  lines.push(html`<div><dt>${t(label)}</dt><dd>${esc(money(amount))}</dd></div>`);
 }
 return lines.length?html`<dl class="shipment-payment-details">${lines.join('')}</dl>`:'';
}

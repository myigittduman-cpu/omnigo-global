import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {money} from './ui.js';

const fields=['originalCents','platformDiscountCents','sellerDiscountCents','discountedCents','reportCents'];
export function orderPricing(order){
 const products=order.products||[];
 if(!products.length)return null;
 const total=Object.fromEntries(fields.map(key=>[key,0]));let basis,couponCents=0;
 const pttInvoice=order.provider==='PTT AVM'&&products.every(product=>product.pricing?.invoiceBasis==='ptt_coupon_included');
 for(const product of products){
  const pricing=product.pricing;
  if(!pricing||!['discounted','seller_invoice'].includes(pricing.reportBasis)||fields.some(key=>!Number.isSafeInteger(pricing[key])||pricing[key]<0))return null;
  if(basis&&basis!==pricing.reportBasis)return null;basis=pricing.reportBasis;
  const expected=basis==='seller_invoice'?pricing.originalCents-pricing.sellerDiscountCents:pricing.discountedCents;
  if(pricing.originalCents-pricing.platformDiscountCents-pricing.sellerDiscountCents!==pricing.discountedCents||pricing.reportCents!==product.grossCents||pricing.reportCents!==expected)return null;
  if(pttInvoice){
   if(!Number.isSafeInteger(pricing.couponCents)||pricing.couponCents<0||pricing.couponCents>pricing.platformDiscountCents)return null;
   couponCents+=pricing.couponCents;if(!Number.isSafeInteger(couponCents))return null;
  }
  for(const key of fields){total[key]+=pricing[key];if(!Number.isSafeInteger(total[key]))return null;}
 }
 return total.reportCents===order.grossCents?{...total,reportBasis:basis,...(pttInvoice?{couponCents,invoiceBasis:'ptt_coupon_included'}:{})}:null;
}
export function orderPricingView(order,{detailed=false}={}){
 const pricing=orderPricing(order);
 if(!pricing)return detailed?i18nHtml('<p class="form-help">Ayrıntılı indirim bilgisi henüz aktarılmadı.</p>'):'';
 const discount=pricing.platformDiscountCents+pricing.sellerDiscountCents;
 if(!discount&&!detailed)return i18nHtml`<small class="order-pricing-note">İndirim yok</small>`;
 const row=(label,amount,extra='')=>i18nHtml`<div ${extra}><dt>${i18nT(label)}</dt><dd>${money(amount)}</dd></div>`;
 if(pricing.invoiceBasis==='ptt_coupon_included')return i18nHtml`<div class="order-pricing"><span class="badge">${discount?i18nT('İndirimli sipariş'):i18nT('İndirim yok')}</span><dl>
  ${row(i18nT('İndirim öncesi'),pricing.originalCents)}
  ${row(i18nT('PttAVM indirimi (kupon hariç)'),pricing.platformDiscountCents-pricing.couponCents)}
  ${row(i18nT('Satıcı indirimi'),pricing.sellerDiscountCents)}
  ${row(i18nT('Satış fiyatı (kupon öncesi)'),pricing.discountedCents+pricing.couponCents)}
  ${row('Kupon',pricing.couponCents)}
  ${row(i18nT('Fatura tutarı'),pricing.reportCents,'class="order-pricing-result"')}
 </dl><small class="order-pricing-note">Kupon toplam indirime dahildir ve bir kez düşülür. İptal ve iadeler ayrıca gösterilir.</small></div>`;
 return i18nHtml`<div class="order-pricing"><span class="badge">${discount?i18nT('İndirimli sipariş'):i18nT('İndirim yok')}</span><dl>
  ${row(i18nT('İndirim öncesi'),pricing.originalCents)}
  ${row('Pazaryeri indirimi',pricing.platformDiscountCents)}
  ${row(i18nT('Satıcı indirimi'),pricing.sellerDiscountCents)}
  ${row(pricing.reportBasis==='seller_invoice'?i18nT('Müşterinin ödediği'):i18nT('İndirim sonrası'),pricing.discountedCents,'class="order-pricing-result"')}
  ${pricing.reportBasis==='seller_invoice'?row(i18nT('Fatura tutarı'),pricing.reportCents):''}
 </dl><small class="order-pricing-note">${pricing.reportBasis==='seller_invoice'?i18nT('Sipariş tutarı, satıcı indirimi düşülmüş fatura tutarıdır. Pazaryerinin karşıladığı indirim fatura tutarından tekrar düşülmez.'):i18nT('Satış hesabında indirim sonrası tutar kullanılır; iptal ve iadeler ayrıca düşülür.')}</small></div>`;
}
export function orderAmountView(order){
 const pricing=orderPricing(order);
 if(pricing&&pricing.platformDiscountCents+pricing.sellerDiscountCents>0)return i18nHtml`<strong>${money(order.grossCents)}</strong><small class="order-discount-label">İndirimli sipariş</small><button type="button" class="text-btn order-pricing-trigger" aria-label="Sipariş tutar dökümünü aç">Tutar dökümü</button>`;
 return i18nHtml`<strong>${money(order.grossCents)}</strong>${pricing?i18nHtml('<small class="order-pricing-note">İndirim yok</small>'):''}`;
}

import {currentLocale,html,t} from './i18n.js';
import {esc,money,platform} from './ui.js';
import {profitabilityCommissionSummary} from './profitability-commission-summary.js';
import {profitabilitySaleAmount,orderMargin} from './profitability-sale-amount.js';
import {profitabilityFeeEntries} from './profitability-fees.js';

export const profitabilityHeaders=['Sipariş / Tarih','Pazaryeri','SKU','Satış tutarı','Komisyon','Diğer kesintiler','Alış maliyeti','Kâr/zarar (%)','Hesaba geçecek tutar'];
const amount=value=>Number.isSafeInteger(value)?money(value):'—';

function orderDate(value,withTime=false){
  if(!value)return '';
  const date=new Date(value);
  if(!Number.isFinite(date.getTime()))return '';
  const options={timeZone:'Europe/Istanbul',day:'numeric',month:'short',year:'numeric'};
  if(withTime)Object.assign(options,{hour:'2-digit',minute:'2-digit'});
  return html`<time class="profitability-order-date" datetime="${date.toISOString()}">${esc(new Intl.DateTimeFormat(currentLocale(),options).format(date))}</time>`;
}

function status(order,summary=null){
  if(order.status==='cancelled')return 'İptal edildi';
  if(order.status==='returned')return 'İade edildi';
  if(order.status==='statement_stale')return 'Son ekstredeki hakediş · Güncellik teyidi bekleniyor';
  if(Number.isSafeInteger(order.afterDeductionsCents))return 'Kesin hakediş · Ekstreyle doğrulandı';
  if(Number.isSafeInteger(summary?.commissionCents)){
    return ['marketplace_rate','marketplace_line_rates','manual'].includes(summary.source)?'Komisyon hesaplandı · Ekstre bekleniyor':'Komisyon kaydı var · Ekstre bekleniyor';
  }
  return 'Ekstre bekleniyor';
}

function orderSkus(order){
  const skus=[...new Set((order.products??[]).map(product=>product.sku).filter(Boolean))];
  if(!skus.length)return '—';
  return html`<ul class="finance-profitability-products">${skus.map(sku=>html`<li><strong>${esc(sku)}</strong></li>`).join('')}</ul>`;
}

export function profitabilityRows(rows=[]){
  return rows.map((order,index)=>{
    const summary=profitabilityCommissionSummary(order);
    const groups=breakdownGroups(order,summary,true);
    const sale=profitabilitySaleAmount(order);
    const payout=Number.isSafeInteger(order.afterDeductionsCents);
    const reported=!payout&&Number.isSafeInteger(order.reportedPayoutCents);
    const margin=orderMargin(order,currentLocale());
    const marginDifference=(order.afterDeductionsCents??order.reportedPayoutCents)-order.purchaseCents;
    const marginTone=margin===null||marginDifference===0?'':marginDifference>0?' profitability-gain':' profitability-loss';
    const interim=!reported&&!payout&&!['cancelled','returned'].includes(order.status)&&Number.isSafeInteger(summary?.remainingCents);
    const payoutLabel=payout?amount(order.afterDeductionsCents):reported?amount(order.reportedPayoutCents):interim?amount(summary.remainingCents):
      t(order.status==='cancelled'?'İptal edildi':order.status==='returned'?'İade edildi':'Ekstre bekleniyor');
    const id=kind=>`profit-${index}-${kind}`;
    const fees=profitabilityFeeEntries(order);
    return html`<tr${order.status==='cancelled'?' class="order-row-cancelled"':''}>
      <th class="profitability-order" scope="row"><strong>${esc(order.orderNumber||'—')}</strong>${orderDate(order.orderedAt)}</th>
      <td>${platform(order.channel)}</td><td class="profitability-skus">${orderSkus(order)}</td>
      ${amountCell(id('sale'),sale.cents??order.salesCents,groups.sales+groups.lines,'Satış ayrıntıları',sale.fromOrder?'Sipariş kaydındaki ürün tutarı':'')}
      <td class="profit-value" data-commission-rate>${Number.isSafeInteger(summary?.rateBasisPoints)?esc(new Intl.NumberFormat(currentLocale(),{style:"percent",maximumFractionDigits:2}).format(summary.rateBasisPoints/10000)):"—"}</td>
      <td class="profit-value">${detailControl(id('fees'),fees.length?amount(fees[0].cents):groups.movements?t('Ekstre hareketleri'):'—','Diğer kesintiler',groups.fees+groups.movements)}</td>
      ${amountCell(id('cost'),order.purchaseCents,groups.costs,'Alış maliyeti')}
      <td class="profit-value profitability-margin${marginTone}" title="${esc(t("(Hakediş − alış maliyeti) / sipariş satış tutarı"))}">
        ${margin===null?'—':html`<strong>${esc(margin)}</strong>${reported||order.status==='statement_stale'?html`<small>Geçici</small>`:''}`}
      </td>
      <td class="profit-order-payout"><span class="profit-mobile-payout-label">${t('Hesaba geçecek tutar')}</span>
        ${detailControl(id('payout'),payout||reported||interim?html`<strong>${esc(payoutLabel)}</strong>`:esc(payoutLabel),'Hesaba geçecek tutar',html`<small>${esc(t(status(order,summary)))}</small>${interim?html`<small>${t('Diğer kesintiler ve kesin hakediş ekstreyle doğrulanır.')}</small>`:''}${order.checkedAt?html`<small>Son kontrol ${orderDate(order.checkedAt,true)}</small>`:''}`)}
        ${reported?html`<small>Pazaryerinin bildirdiği hakediş</small>`:''}${interim?html`<small>${t('Komisyon sonrası ara tutar')}</small>`:''}
      </td></tr>`;
  });
}

function detailControl(id,main,label,detail){
  const trigger=detail?html`<button class="profit-detail-toggle" type="button" popovertarget="${id}" aria-label="${esc(t(label))}" title="${esc(t(label))}"><svg viewBox="0 0 20 20" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 7 5 5 5-5"/></svg></button>`:'';
  return html`<div class="profit-amount-line">${trigger}<span>${main}</span></div>${detail?html`<div popover="auto" id="${id}" class="profit-detail-panel" aria-label="${esc(t(label))}">${detail}</div>`:''}`;
}

function amountCell(id,value,detail,label,source=''){
  const main=Number.isSafeInteger(value)?amount(value):detail?esc(t(label)):'—';
  return html`<td class="profit-value">${detailControl(id,main,label,detail)}${source?html`<small>${esc(t(source))}</small>`:''}</td>`;
}

function detailRow(label,formatted,note='',attribute=''){
  return html`<div${attribute}><dt>${esc(label)}${note?html`<small>${esc(note)}</small>`:''}</dt><dd>${formatted}</dd></div>`;
}

function field(label,value,note='',attribute=''){
  if(!Number.isSafeInteger(value))return '';
  return detailRow(label,amount(value),note,attribute);
}

function section(title,fields){
  const content=fields.join('');
  return content?html`<section><h3>${esc(t(title))}</h3><dl class="profit-breakdown-list">${content}</dl></section>`:'';
}

const commissionSources={marketplace:'Pazaryeri komisyon kaydı',marketplace_effective:'Pazaryerinin bildirdiği komisyon tutarı',marketplace_sale:'Satış hareketi komisyonu',marketplace_net:'Pazaryeri sipariş kaydı',marketplace_rate:'Pazaryerinin bildirdiği oranla hesaplandı',marketplace_line_rates:'Ürünlerin pazaryeri oranlarıyla hesaplandı',manual:'Mağazanın girdiği oranla hesaplandı'};
const movementNames={Commission:'Komisyon',CampaignDiscount:'Kampanya desteği',PaymentServiceCostReflection:'Ödeme hizmet bedeli',ProcessingFeeExpense:'İşlem hizmet bedeli'};

function movementField(item){
  const due=item.dueDate?orderDateText(item.dueDate):'';
  const notes=[t(item.status==='Paid'?'Ödendi':'Ödenecek'),due];
  if(Number.isSafeInteger(item.netCents))notes.push(t('KDV hariç')+' '+amount(item.netCents));
  if(Number.isSafeInteger(item.taxCents))notes.push(t('KDV')+' '+amount(item.taxCents));
  return field(t(movementNames[item.type]||'Diğer hareket'),item.grossCents,notes.filter(Boolean).join(' · '));
}

function orderDateText(value){
  const date=new Date(value);
  return Number.isFinite(date.getTime())?new Intl.DateTimeFormat(currentLocale(),{timeZone:'Europe/Istanbul'}).format(date):'';
}

function breakdownGroups(order,summary,inline=false){
  const p=order.orderPricing||{},items=order.statementItems||[],products=order.products||[];
  const source=summary?.source??order.commissionSource;
  const rate=summary?.rateBasisPoints;
  const label=['marketplace_rate','marketplace_line_rates','manual'].includes(source)?'Oranla hesaplanan komisyon':'Uygulanan komisyon';
  const perProduct=products.length>1||products[0]?.quantity>1;
  const quantity=product=>String(product.quantity)+' '+t('adet');
  const sales=section('Satış ve sepet indirimleri',[
    !inline||order.storePriceBasis==='buyer_plus_discounts'?field(t('Sipariş anındaki mağaza satış tutarı'),p.storePriceCents,order.storePriceBasis==='buyer_plus_discounts'?t('Alıcının ürün tutarı + siparişte bildirilen indirimler'):''):'',
    field(t('Mağazanın karşıladığı indirim'),p.sellerDiscountCents),field(t('Pazaryerinin karşıladığı sepet desteği'),p.platformDiscountCents),
    field(t('Alıcının ürün tutarı'),p.buyerPaidCents),
    (!inline||Number.isSafeInteger(p.storePriceCents))&&![p.storePriceCents,p.buyerPaidCents].includes(order.salesCents)?field(t(profitabilitySaleAmount(order).fromOrder?'Sipariş kaydındaki ürün tutarı':'Sipariş kaydındaki satış tutarı'),order.salesCents):'',
  ]);
  const lines=perProduct?section('Ürün bazında satış tutarları',products.map(product=>{
    const offer=product.orderPricing?.storePriceCents;
    const fromOrder=!Number.isSafeInteger(offer);
    return field(product.sku||product.title||t('Ürün'),fromOrder?product.salesCents:offer,
      quantity(product)+(fromOrder?' · '+t('Sipariş kaydındaki ürün tutarı'):''));
  })):'';
  const commission=section('Komisyon',[
    ![p.storePriceCents,p.buyerPaidCents,order.salesCents].includes(summary?.basisCents)?field(t('Komisyona esas satış tutarı'),summary?.basisCents):'',
    Number.isSafeInteger(rate)?detailRow(t(['marketplace_effective','marketplace_line_rates'].includes(source)?'Efektif komisyon oranı':'Komisyon oranı'),
      esc(new Intl.NumberFormat(currentLocale(),{style:'percent',maximumFractionDigits:2}).format(rate/10000)),'',' data-commission-rate'):'',
    inline&&Number.isSafeInteger(summary?.commissionCents)
      ? (commissionSources[source]?html`<div><dt>${t('Komisyon kaynağı')}</dt><dd class="profit-source-note">${esc(t(commissionSources[source]))}</dd></div>`:'')
      : field(t(label),summary?.commissionCents,t(commissionSources[source]||''),' data-commission-amount'),
    source==='marketplace_net'&&summary?.commissionCents==null?field(t('Komisyon (KDV hariç)'),order.commissionNetCents,t('Pazaryeri sipariş kaydı')):'',
    field(t('Komisyon sonrası ara tutar'),summary?.remainingCents,t('Diğer kesintiler öncesi'),' data-commission-remainder'),
    field(t('Komisyon indirimi'),p.commissionDiscountCents??order.commissionDiscountCents),
  ]);
  const fees=section('Diğer kesintiler',profitabilityFeeEntries(order).map(({label,cents,fromStatement})=>
    field(t(label),cents,fromStatement?t('Eşleşen ekstre hareketlerinden'):'')));
  const movements=section('Pazaryeri ekstresindeki hareketler',[
    ...items.map(movementField),field(t('Ekstredeki kupon hareketi'),order.statementCouponCents),
    field(t('Ekstredeki kargo kesintisi'),order.statementShippingCents),
  ]);
  const costs=section('Alış maliyeti',[
    ...products.filter(product=>!inline||perProduct||product.unitPurchaseCents!==order.purchaseCents).map(product=>field(product.sku||product.title||t('Ürün'),product.unitPurchaseCents,quantity(product)+' · '+t('Birim alış maliyeti'))),
    inline?'':field(t('Toplam alış maliyeti'),order.purchaseCents),
  ]);
  return {sales,lines,commission,fees,movements,costs};
}

export function profitabilityBreakdown(order,summary=profitabilityCommissionSummary(order)){
  return Object.values(breakdownGroups(order,summary)).join('');
}

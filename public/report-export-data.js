import {turnoverDescription,rawTurnoverValue,turnoverValue,salesTurnoverVisible,customerTurnover} from './sales-turnover.js';
import {analysisMeasures,analysisDeductionLabel,showAfterCancellationTurnover} from './analysis-metrics.js';
import {rankBestsellers} from './report-bestsellers.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {paymentLabel} from './payment-calendar.js';
export const exportText=value=>String(value??'').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f]/g,'').slice(0,2000);
export function reportTables(r){
 const amounts=salesTurnoverVisible(r),asLira=value=>value===null?null:value/100;
 const amountLabel=i18nT(!r.hideMarketplaceSalesCancellations&&r.turnoverDisplayBasis==='after_cancellations'?'İptal sonrası ciro':'Ciro')+' (TL)';
 const incomplete=r.series.some(series=>series.days.some(day=>day.coverageLimited));
 const notes=[i18nText`${r.availableChannels}/${r.selectedChannels} pazaryerinin günlük verisi mevcut.`,...r.series.filter(s=>!s.available).map(s=>s.channel+': '+s.message),...r.notes,...(incomplete?[i18nT('Toplamlar doğrulanmış kayıtları içerir.')]:[]),i18nT('Hakedişler yönetici tarafından elle girilir. Eski otomatik kayıtlar toplam ve takvim dışında tutulur.'),...(amounts&&!r.hideMarketplaceSalesCancellations?[i18nT(turnoverDescription(r))]:[]),i18nT(!r.hideMarketplaceSalesCancellations&&r.turnoverDisplayBasis==='after_cancellations'?'Sipariş sayısı, seçili dönemde tamamen iptal edilmemiş siparişleri gösterir.':'Sipariş sayısı, seçili dönemdeki sipariş kayıtlarının sayısıdır.'),i18nT('Gün içindeki veriler son aktarım saatine kadardır. Boş hücre: veri yok / teyit bekliyor.')];
 const current=analysisMeasures(r.totals,r.refundsVerified,r.sensitiveVisibility),previous=analysisMeasures(r.previousTotals,r.refundsVerified,r.sensitiveVisibility);
 const measure=(label,key,transform=value=>value)=>[i18nT(label),transform(current[key]),transform(previous[key])];
 const deduction=analysisDeductionLabel(r);
 const productAfter=amounts&&customerTurnover(r)&&showAfterCancellationTurnover(r);
 const summary=[
  ...(amounts?[measure(amountLabel,'turnover',asLira)]:[]),
  ...(showAfterCancellationTurnover(r)?[measure('İptaller Sonrası Ciro (TL)','afterCancellations',asLira)]:[]),
  measure('Sipariş sayısı','orders'),
  ...(amounts?[measure('Sipariş Başına Satış (TL)','average',asLira)]:[]),
  ...(deduction?[measure(i18nT(deduction)+' (TL)','deductions',asLira)]:[]),
  measure('Satılan ürün adedi','units'),
 ];
 return [
  {name:i18nT('Özet'),head:[i18nT('Gösterge'),i18nT('Seçili dönem'),i18nT('Önceki dönem')],rows:summary,money:amounts?[1,2]:[]},
  {name:i18nT('Günlük satışlar'),head:[i18nT('Pazaryeri'),i18nT('Gün'),...(amounts?[amountLabel]:[]),i18nT('Sipariş'),i18nT('Kapsam')],rows:r.series.flatMap(s=>s.days.map(d=>{
   const value=amounts?rawTurnoverValue(d):d.orders??null;
   const coverage=d.coverageLimited?(value===null?'Teyit bekliyor':'Kısmi veri'):value===null?'Veri yok':d.partial?'Kısmi gün':'Tam gün';
   return [s.channel,d.date,...(amounts?[asLira(value)]:[]),d.orders,i18nT(coverage)];
  })),money:amounts?[2]:[]},
  {name:i18nT('En çok satanlar'),head:[i18nT('Sıra'),i18nT('Ürün kodu'),i18nT('Ürün'),i18nT('Pazaryerleri'),i18nT(customerTurnover(r)?'Satış adedi':'Net adet'),...(amounts?[amountLabel]:[]),...(productAfter?[i18nT('İptaller Sonrası Ciro (TL)')]:[])],rows:rankBestsellers(r.products).map(p=>[p.rank,p.sku,p.title,p.channels.join(', '),p.netQuantity,...(amounts?[asLira(customerTurnover(r)?rawTurnoverValue(p):turnoverValue(p))]:[]),...(productAfter?[asLira(p.salesTurnoverCents)]:[])]),money:productAfter?[5,6]:amounts?[5]:[]},
  {name:i18nT('Hakedişler'),head:[i18nT('Pazaryeri'),i18nT('Planlanan tarih'),i18nT('Beklenen (TL)'),i18nT('Durum'),i18nT('Hesaba geçiş'),i18nT('Gerçekleşen (TL)'),i18nT('Kaynak / teyit')],rows:[...r.payments.items,...(r.payments.undatedItems||[])].map(p=>[p.channel,p.expectedDate||i18nT("Tarih belirlenmedi"),p.expectedCents/100,paymentLabel(p)+(p.status==='sent'?i18nT(' · Aktarım bildirildi'):''),p.paidDate||null,p.actualCents==null?null:p.actualCents/100,p.status==='paid'?p.confirmation:p.verificationSource||p.source]),money:[2,5]},
  {name:i18nT('Veri kapsamı'),head:[i18nT('Bilgi'),i18nT('Açıklama')],rows:[[i18nT('Firma'),r.company.name],[i18nT('Dönem'),r.from+' – '+r.to],[i18nT('Önceki dönem'),r.previousFrom+' – '+r.previousTo],[i18nT('Oluşturulma'),r.generatedAt],...r.series.filter(s=>s.asOf).map(s=>[i18nText`${s.channel} son aktarım`,s.asOf]),...notes.map(n=>[i18nT('Not'),n])],money:[]}
 ];
}



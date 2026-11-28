import {refundCoverageNote} from './sales-refund-notice.js';
import {rawTurnoverMoney,cancelledTurnoverMoney,turnoverValue,turnoverMoney,turnoverTotal,turnoverDays,turnoverNote,salesTurnoverVisible,customerTurnover} from './sales-turnover.js';
import {salesPeriodMetrics} from './sales-period-metrics.js';
import {customerSensitiveVisibility} from './customer-sensitive-visibility.js';
import {customerCan} from './customer-access.js';
import {usesCustomerOverviewTabs} from './customer-overview-layout.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {paymentRecords,paymentCoverage} from './payment-records.js';
import {paymentIsApproved} from './payment-approval.js';
import {salesFreshness,paymentFreshness} from './freshness.js';
import {activeSalesOverview} from './sales-panels.js';
import {channelCards} from './channel-cards.js';
import {salesDetails} from './sales-details.js';
import {monthlySales} from './monthly-sales.js';
import {overviewCharts} from './balanced-overview.js';
import {overviewSections,overviewTopContent} from './overview-sections.js';
import {customerWorkContent} from './customer-work.js';
import {overviewExpectedThisMonth,overviewPaymentForecast,overviewPaymentPeriods,overviewPayments} from './overview-payments.js';
import {now,esc,money,date,platform,badge,empty,icon} from './ui.js';
const edit=(kind,r,admin)=>admin&&!r.integration&&!r.legacyManual&&!(kind==='payments'&&paymentIsApproved(r))?i18nHtml`<button class="text-btn" data-edit="${kind}" data-id="${r.id}">Düzenle ${icon('arrow')}</button>`:'';
function pendingRefundNotice(data){
 if(data.sensitiveVisibility?.returns===false)return '';
 const channels=[...new Set(data.sales.filter(row=>row.refundsVerified===false).map(row=>row.channel).filter(channel=>typeof channel==='string'&&channel.trim()))];
 return channels.length?i18nText`${channels.join(', ')} için iade verileri henüz aktarılmadı.`:i18nT('Bazı pazaryerlerinin iade verileri henüz aktarılmadı.');
}
function salesStatCards(data,showDetails=true,grossOnly=false){
 const s=data.summary,client=customerTurnover(data),value=fn=>data.sales.length?fn(s):'—';
 const detail=showDetails?i18nHtml`<span class="stat-foot">Seçili dönemin kayıtları<br>${esc(salesFreshness(data))}</span>`:'';
 const selected=data.dateRange,period=selected&&(selected.from!==now().slice(0,7)+'-01'||selected.to!==now())?'Seçili dönem':'Bu Ay';
 const monthTurnover=data.marketplaceSales?.currentMonthTurnoverCents;
 let turnover='';
 if(salesTurnoverVisible(data)){
  turnover=client
   ?i18nHtml`<article class="stat featured stat--money"><span class="stat-label">${i18nT('Bu ay')}</span><strong>${Number.isSafeInteger(monthTurnover)?money(monthTurnover):i18nT('Bekleniyor')}</strong></article>`
   :i18nHtml`<article class="stat featured"><span class="stat-label">${i18nT(period)}</span><dl class="turnover-card-rows"><div><dt>Ciro</dt><dd>${value(rawTurnoverMoney)}</dd></div>${grossOnly?'':i18nHtml`<div><dt>İptal tutarı</dt><dd>${value(cancelledTurnoverMoney)}</dd></div><div class="turnover-card-net"><dt>İptaller sonrası ciro</dt><dd>${value(turnoverMoney)}</dd></div>`}</dl></article>`;
 }
 return turnover+i18nHtml`<article class="stat"><span class="stat-label">Sipariş sayısı</span><strong>${data.sales.length?s.orders:'—'}</strong>${detail}</article>`;
}
export function stats(data,{payments=true,showDetails=true,periodMetrics=null,forecast=null,overview=false}={}){
 const s=data.summary;
 let paymentStats='';
 if(payments){
  const details=[];
  if(showDetails)details.push(i18nT('Yöneticinin planladığı ödeme kayıtları'));
  if(s.undatedCount)details.push(i18nHtml`Tarih belirlenmedi: ${money(s.undatedCents)} · Aylık toplama dahil değil`);
  if(showDetails)details.push(esc(paymentFreshness(data)));
  const expectedDetail=details.length?i18nHtml`<span class="stat-foot">${details.join('<br>')}</span>`:'';
  const receivedDetail=showDetails?i18nHtml`<span class="stat-foot">Seçili dönemde Omnigo tarafından onaylanmış</span>`:'';
  const expected=overview?overviewExpectedThisMonth(forecast):data.payments.length?s.expectedCents:null;
  const paid=overview
   ?data.overviewPaymentPeriods?.month?.paidCents??forecast?.totals?.paidThisMonthCents
   :data.payments.length?s.paidCents:null;
  paymentStats=i18nHtml`<article class="stat stat--money"><span class="stat-label">${i18nT(overview?'Bu Ay Beklenen Hakediş':'Beklenen hakediş')}</span><strong>${expected==null?'—':money(expected)}</strong>${expectedDetail}</article>
  <article class="stat stat--money stat--paid"><span class="stat-label">${i18nT(overview?'Bu Ay Hesaba Geçen':'Hesaba geçen')}</span><strong>${paid==null?'—':money(paid)}</strong>${receivedDetail}</article>`;
 }
 const sales=periodMetrics?'':salesStatCards(data,showDetails,overview);
 const statsClass=sales?(customerTurnover(data)?' stats--customer':''):' stats--payments-pair';
 const grouped=overview&&periodMetrics&&payments&&data.overviewPaymentPeriods;
 const summary=grouped?overviewPaymentPeriods(data.overviewPaymentPeriods,paymentStats):
  sales||paymentStats?i18nHtml`<div class="stats${statsClass}">${sales}${paymentStats}</div>`:'';
 return (periodMetrics?salesPeriodMetrics(periodMetrics):'')+
  summary+
  (payments?paymentCoverage(data.missingPaymentChannels):'');
}
export function channelTable(state){return monthlySales(state);}
export function paymentTable(state,compact=false){
 const admin=state.user.role==='admin';
 let rows=[...state.data.payments].filter(p=>!state.filter||state.filter==='all'||(state.filter==='pending'?!paymentIsApproved(p):p.status==='paid'&&paymentIsApproved(p))).sort((a,b)=>a.expectedDate.localeCompare(b.expectedDate));
 const undated=compact?rows.filter(p=>!p.expectedDate&&p.status!=='paid'&&!p.excludedFromTotals):[];
 if(compact)rows=rows.filter(p=>p.status==='pending'&&p.expectedDate>=now()&&!p.excludedFromTotals).slice(0,4);
 const dated=rows.length?i18nHtml`<div class="table-scroll"><table class="payments-table${compact?' payments-table--upcoming':''}"><thead><tr><th>Pazaryeri</th><th>Beklenen tutar</th><th>Ödeme tarihi</th><th>Durum</th><th>Detay</th></tr></thead><tbody>${rows.map(p=>{
  const source=p.integration?i18nT('Resmi API kaydı'):p.legacyManual?i18nT('Eski manuel kayıt · Güncel toplama dahil değil'):'';
  return i18nHtml`<tr><td>${platform(p.channel)}</td><td><strong>${money(p.expectedCents)}</strong></td><td>${p.expectedDate?date(p.expectedDate):i18nT('Tarih belirlenmedi')}</td><td>${badge(paymentIsApproved(p)?'paid':p.status==='paid'?'sent':p.status)}${source?i18nHtml`<small class="payment-source">${source}</small>`:''}</td><td><button class="text-btn" data-payment="${p.id}">İncele ${icon('arrow')}</button>${edit('payments',p,admin)}</td></tr>`;
 }).join('')}</tbody></table></div>`:empty(i18nT('Hakediş kaydı bulunmuyor'),i18nT('Bu dönem için doğrulanmış bir ödeme kaydı henüz eklenmedi.'));
 return dated+(undated.length?i18nHtml`<h3>Tarih belirlenmedi</h3><p class="panel-note">Ödeme tarihi henüz açıklanmamış tutarlar aylık toplama dahil değildir. Tarih bildirildiğinde takvimde otomatik görünür.</p>${paymentRecords(undated,admin)}`:'');
}
export function tasks(state,compact=false){let rows=state.data.tasks;if(compact)rows=rows.slice(0,3);return rows.length?i18nHtml`<section class="task-list">${rows.map(t=>i18nHtml`<article class="task"><div class="task-top"><span class="eyebrow">${esc(t.channel==='Tüm kanallar'?i18nT(t.channel):t.channel)}</span>${badge(t.status)}</div><h3>${esc(t.title)}</h3><p>${esc(t.description)}</p><div class="task-meta"><span>${esc(t.owner)}${state.user.role==='admin'?i18nHtml`<br>${t.visibility==='internal'?i18nT('Yalnızca ekip'):i18nT('Müşteriye açık')}`:''}</span><span>${date(t.date)}</span></div>${edit('tasks',t,customerCan(state,'tasks','edit'))}</article>`).join('')}</section>`:empty(i18nT('Çalışmalar burada paylaşılacak'),i18nT('Ekibin paylaştığı çalışmalar ve sonraki adımlar bu alanda görünecek.'));}
const marketplaceOverview=state=>monthlySales(state,{grossOnly:true})+activeSalesOverview(state,{separateProducts:true,grossOnly:true});
export function content(state){const {data,view}=state;const admin=state.user.role==='admin';
 if(view==='fileCenter'&&customerCan(state,'fileCenter'))return '<section class="panel file-center" data-file-center></section>';
 if(view==='overviewSales')return marketplaceOverview(state);
 if(view==='overviewTop')return overviewTopContent(state);
 if(view==='overview'){
  const restricted=Array.isArray(data.workspaceAccess?.overviewSections);
  const showPayments=!restricted&&customerCan(state,'payments');
  const forecast=showPayments?overviewPaymentForecast(data):null;
  const summary=overviewCharts(state)+(showPayments?overviewPayments(forecast):'');
  const periodMetrics=admin?data.marketplaceSales?.periodMetrics?.all:null;
  const tabbed=usesCustomerOverviewTabs(state);
  const work=!admin&&!tabbed&&customerCan(state,'overviewWork')?customerWorkContent(state):'';
  const header=stats(data,{payments:showPayments,showDetails:false,periodMetrics,forecast,overview:true});
  return admin||tabbed?overviewSections(state,{sales:admin?(restricted?'':marketplaceOverview(state)):customerCan(state,'overviewSales')?marketplaceOverview(state):'',summary,header}):header+work+summary;
 }
 if(view==='channels')return i18nHtml`<div class="stats sales-overview-stats">${salesStatCards(data)}</div>`+channelCards(state)+(admin?salesDetails(state,channelTable(state)):monthlySales(state));
 if(view==='payments'&&!admin)return i18nHtml`<section class="panel"><div class="panel-heading"><h2>Aktif hakedişler</h2></div>${paymentTable({...state,filter:'pending'})}<p class="panel-note">Beklenen ve aktarımı bildirilen ödemeler gösterilir. Aktarım bildirimi, paranın hesaba geçtiği anlamına gelmez.</p></section>`;
 if(view==='payments')return stats(data)+i18nHtml`<div class="toolbar"><div class="filters">${[['all',i18nT('Tümü')],['pending','Beklenen'],['paid',i18nT('Hesaba geçen')]].map(([v,l])=>i18nHtml`<button data-filter="${v}" class="${state.filter===v?'active':''}">${l}</button>`).join('')}</div></div><section class="panel">${paymentTable(state)}<p class="panel-note">Ödeme tarihinin gelmesi, paranın hesaba geçtiği anlamına gelmez. Aktarım bildirimi ve banka teyidi ayrı durumlar olarak izlenir.</p></section>`;
 if(view==='tasks')return tasks(state);
 if(view==='reports')return data.reports.length?i18nHtml`<div class="report-grid">${data.reports.map(r=>i18nHtml`<article class="panel"><div class="panel-heading"><h2>${esc(r.month)}</h2>${badge(r.status)}</div><div class="report-card"><div class="report-cover">${icon('reports')}<strong>${esc(r.title)}</strong></div><p>${esc(r.content.slice(0,160))}${r.content.length>160?'…':''}</p><div class="report-actions"><button class="btn primary" data-report="${r.id}">Raporu incele ${icon('arrow')}</button>${edit('reports',r,customerCan(state,'reports','edit'))}</div></div></article>`).join('')}</div>`:empty(i18nT('Bu döneme ait rapor henüz yok'),admin?i18nT('Rapor hazırlayabilir, taslak olarak saklayabilir ve hazır olduğunda yayımlayabilirsiniz.'):i18nT('Ekibiniz raporu yayımladığında burada görebileceksiniz.'));
 return '';
}



import {refundCoverageNote} from './sales-refund-notice.js';
import {customerSensitiveVisibility} from './customer-sensitive-visibility.js';
import {t,html,currentLocale} from './i18n.js';
import {esc,money,platform} from './ui.js';
import {rawTurnoverValue,rawTurnoverMoney,turnoverLabel,turnoverDescription,cancelledTurnoverMoney,turnoverMoney,turnoverTotal,salesTurnoverVisible,customerTurnover} from './sales-turnover.js';

export function monthlySales(state,{grossOnly=false}={}){
 const {data,month}=state;
 const visibility=data.tableSensitiveVisibility?.overviewSales||data.sensitiveVisibility||customerSensitiveVisibility(state,{},'overviewSales');
 const amounts=salesTurnoverVisible(data),explicit=state.user?.role==='client';
 const cancellations=amounts&&(!grossOnly||explicit)&&visibility.cancellations,returns=amounts&&(!grossOnly||explicit)&&visibility.returns;
 const adjusted=data.turnoverDisplayBasis==='after_cancellations';
 const after=!adjusted&&amounts&&explicit&&visibility.afterCancellationTurnover;
 const rows=data.channels.map(channel=>({channel,sale:data.sales.find(s=>s.channel===channel.name&&s.month===month)})).filter(({channel,sale})=>channel.status==='active'||sale);
 const label=data.dateRange?[data.dateRange.from,data.dateRange.to].map(d=>d.split('-').reverse().join('.')).join(' – '):new Date(month+'-01T12:00:00+03:00').toLocaleDateString(currentLocale(),{timeZone:'Europe/Istanbul',month:'long',year:'numeric'});
 const total=amounts&&rows.every(row=>row.sale)?turnoverTotal(rows.map(row=>row.sale),grossOnly?rawTurnoverValue:undefined):null;
 const amountLabel=adjusted?'Ciro':grossOnly?'Aktif ciro':'Satış tutarı';
 const note=amounts&&!grossOnly?[adjusted?'':t(turnoverDescription(data)),refundCoverageNote(data)].filter(Boolean).join(' '):'';
 const totalLabel=adjusted?'Ciro':grossOnly?'Aktif ciro':customerTurnover(data)?'Toplam satış':turnoverLabel(data);
 const salesAmount=grossOnly?rawTurnoverMoney:turnoverMoney;
 return html`<section class="panel monthly-sales" aria-labelledby="monthlySalesTitle"><div class="monthly-sales-heading"><div><span class="eyebrow">${esc(label)}</span><h2 id="monthlySalesTitle">Satış dağılımı</h2><p>Seçili dönemin kayıtları · ${rows.length} pazaryeri</p></div>${amounts?html`<div class="monthly-sales-total"><span>${esc(t(totalLabel))}</span><strong>${total===null?t('Bekleniyor'):money(total)}</strong></div>`:''}</div><div class="table-scroll"><table><thead><tr><th scope="col">Pazaryeri</th>${amounts?html`<th scope="col">${t(amountLabel)}</th>`:''}${after?html`<th scope="col">İptaller Sonrası Ciro</th>`:""}${cancellations?'<th scope="col">İptal</th>':''}${returns?'<th scope="col">Onaylı iade</th>':''}<th scope="col">Sipariş</th></tr></thead><tbody>${rows.map(({channel,sale})=>html`<tr><th scope="row">${platform(channel.name)}${sale?'':html`<small>Veri bekleniyor</small>`}</th>${amounts?html`<td data-label="${t(amountLabel)}" class="monthly-net"><strong>${sale?salesAmount(sale):'—'}</strong></td>`:''}${after?html`<td data-label="İptaller Sonrası Ciro">${Number.isSafeInteger(sale?.salesTurnoverCents)?money(sale.salesTurnoverCents):t("Bekleniyor")}</td>`:""}${cancellations?html`<td data-label="İptal" class="monthly-deduction">${sale?cancelledTurnoverMoney(sale):'—'}</td>`:''}${returns?html`<td data-label="Onaylı iade" class="monthly-deduction">${sale&&sale.refundsVerified!==false?money(sale.returnCents):t('Bekleniyor')}</td>`:''}<td data-label="Sipariş">${sale?esc(sale.orders??'—'):'—'}</td></tr>`).join('')}</tbody></table></div>${state.user?.role==='admin'?html`<div class="monthly-sales-actions"><button class="btn secondary" data-add="sales">Satış özeti ekle</button></div>`:''}${note?html`<p class="panel-note">${esc(note)}</p>`:''}</section>`;
}

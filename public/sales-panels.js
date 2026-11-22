import {turnoverLabel,turnoverDescription,turnoverValue,turnoverMoney,turnoverTotal,turnoverDays,turnoverNote} from './sales-turnover.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {currentLocale} from './i18n.js';
import {marketplaceSummary} from './marketplace-summary.js';
import {esc, money, platform, badge, empty, now, icon} from './ui.js';

export const salesInspectionLink=channel=>i18nHtml`<button type="button" class="text-btn channel-sales-link" data-sales-channel="${esc(channel)}">Satışları ve TOP 10’u incele ${icon('arrow')}</button>`;

export function channelSalesMetrics(channel, sales, month, salesToday={date:now(),channels:[]}) {
 const record=sales.find(sale=>sale.channel===channel.name && sale.month===month);
 const net=turnoverValue(record);
 const orders=record?new Intl.NumberFormat(currentLocale()).format(record.orders):'—';
 const source=record?(record.integration?i18nT('Pazaryeri aktarımı'):i18nT('Paylaşılan satış özeti')):i18nT('Veri bekleniyor');
 const status=channel.connection==='error'?i18nT(' · Güncellik kontrol edilmeli'):'';
 const daily=salesToday.channels.find(item=>item.channel===channel.name);
 const monthLabel=month===salesToday.date.slice(0,7)?i18nT('Bu ay toplam satış'):i18nText`${new Date(month+'-01T12:00:00').toLocaleDateString(currentLocale(),{month:'long',year:'numeric'})} toplamı`;
 const dayLabel=new Date(salesToday.date+'T12:00:00').toLocaleDateString(currentLocale(),{day:'numeric',month:'long'});
 return i18nHtml`<div class="channel-sales-metrics"><div><span>Bugünkü satış</span><small>${esc(dayLabel)}</small><strong>${daily?.available?turnoverMoney(daily):'—'}</strong><small>${daily?.available?i18nText`${esc(daily.orders)} sipariş`:i18nT('Günlük veri bekleniyor')}</small></div><div><span>${esc(monthLabel)}</span><small>${month===salesToday.date.slice(0,7)?i18nT('Ayın başından bugüne'):i18nT('Seçili ay')}</small><strong>${record?money(net):'—'}</strong><small>${record?i18nText`${esc(orders)} sipariş`:i18nT('Veri bekleniyor')}</small></div></div><p class="channel-sales-source">${esc(source+status)} · ${esc(i18nT(turnoverLabel(record)))}</p>`;
}

export function activeSalesOverview(state,{separateProducts=false,grossOnly=false}={}) {
 const channels=state.data.channels.filter(channel=>channel.status==='active');
 if(!channels.length)return empty(i18nT('Henüz aktif pazaryeri yok'),i18nT('Aktif mağazalarınız ve satışları burada görünecek.'));
 return i18nHtml`<section class="active-sales" aria-labelledby="activeSalesTitle"><div class="section-heading"><div><h2 id="activeSalesTitle">Pazaryeri satış özetleri</h2><p>Bugün, son 7 gün ve son 30 gün · ${channels.length} aktif mağaza</p></div></div><div class="active-sales-grid">${channels.map(channel=>i18nHtml`<article class="panel active-sales-card"><header>${platform(channel.name)}${badge('active')}</header>${marketplaceSummary(state,channel.name,grossOnly)}${separateProducts?i18nHtml`<button type="button" class="text-btn channel-sales-link" data-sales-channel="${esc(channel.name)}" data-sales-only>Satış ayrıntıları ${icon('arrow')}</button>`:salesInspectionLink(channel.name)}</article>`).join('')}</div><p class="panel-note">Adet, satılan ürün sayısıdır. Gün içi değerler son aktarım saatine kadardır. Eksik geçmiş için karşılaştırma yüzdesi gösterilmez. Komisyon ve giderler düşülmemiştir.</p></section>`;
}

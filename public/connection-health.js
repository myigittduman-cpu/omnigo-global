import {loadingState} from './content-state.js';
import {labelMap as localizedLabels} from './i18n.js';
import {connectionMessage} from './status-label.js';
import {currentLocale} from './i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc,money,platform} from './ui.js';
const labels=localizedLabels({not_configured:'Bağlantı bekliyor',error:'Kontrol gerekli',missing_queue:'Kuyruk eksik',waiting:'İlk aktarım bekleniyor',running:'Aktarılıyor',retry:'Yeniden deneme bekliyor',stale:'Güncel değil',probe:'Kısmi kapsam',ready:'Aktarım sağlıklı',manual:'Manuel',on_demand:'İstek üzerine',inbox_open:'Sekme açıldığında',scan_queued:'Sırada',inactive:'Pasif'});
const timestamp=value=>value?new Date(value).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul'}):'—';
const serviceLabels={questions:'Pazaryeri soruları',payouts:'Yaklaşan hakedişler',requests:'İptal ve iade talepleri'};
function healthChart(items){
 const services = {orders:'Sipariş ve mutabakat',catalog:'Ürün ve katalog',updates:'Stok ve fiyat güncelleme',questions:'Müşteri iletişimi',requests:'İptal ve iade',payouts:'Finans ve hakediş'};
 const rows = items.map(item=>{
  const cells = Object.entries(services).map(([key,title])=>{
   const status = key === 'orders' ? (item.active ? item.status : 'inactive') : item.services?.[key]?.status;
   const healthy = Boolean(item.active) && status === 'ready' && (key !== 'orders' || item.reconciliation?.state === 'healthy');
   const scope = {orders:'Sipariş',questions:'Sorular',payouts:'Yaklaşan hakediş'};
   const base = status ? (scope[key] ? scope[key]+' · ' : '')+(labels[status]||'Durum bilinmiyor') : 'Durum raporlanmıyor';
   const reconciliation = key==='orders' && item.reconciliation ? ({healthy:'Mutabakat sağlandı',issue:'Mutabakat farkı var'}[item.reconciliation.state]||'Mutabakat bekleniyor') : '';
   const text = reconciliation ? base+' · '+reconciliation : base;
   let failed = item.active && (status === 'error' || status === 'missing_queue' || (key === 'orders' && item.reconciliation?.state === 'issue'));
   let symbol = healthy ? '✓' : failed ? '✕' : (status === 'ready' ? reconciliation || 'Henüz doğrulanmadı' : labels[status] || 'Henüz doğrulanmadı');
   let hint = text;
   if(key === 'updates'){
    const enabled=item.updatesEnabled!==false;
    failed=enabled && item.updateResult==='error';
    symbol=!enabled?'Kullanılmıyor':failed?'✕':item.updateResult==='ready'?'✓':'Henüz doğrulanmadı';
    hint=failed?'Son stok veya fiyat işlemi başarısız':symbol==='✓'?'Son stok veya fiyat işlemi doğrulandı':symbol;
   }
   if(key === 'catalog'){
    const read = item.catalogRead || {};
    failed = Boolean(read.error) && Number(read.attempts)>0;
    symbol = failed ? '✕' : read.error ? 'Kısmi okuma' : read.last_success ? '✓' : 'Henüz doğrulanmadı';
    hint = read.error || (read.last_success ? 'Son başarılı okuma · '+timestamp(read.last_success) : symbol);
   }
   return i18nHtml`<td><small class="health-mobile-label">${i18nT(title)}</small><span title="${esc(hint)}" aria-label="${esc(hint)}" class="${symbol==='✓'?'health-result is-healthy':failed?'health-result needs-check':'health-neutral'}">${esc(symbol)}</span></td>`;
  }).join('');
  return i18nHtml`<tr><th scope="row">${esc(item.channel)}</th>${cells}</tr>`;
 }).join('');
 return i18nHtml`<section class="report-panel health-overview" aria-label="Aktarım özeti"><div class="report-section-title"><h2>Aktarım özeti</h2></div>
 <table class="health-matrix"><thead><tr><th scope="col">Pazaryeri</th>${Object.values(services).map(title=>i18nHtml`<th scope="col">${i18nT(title)}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table></section>`;
}
function serviceList(services={},prefix){
 if(!Object.keys(services).length)return '';
 return i18nHtml`<ul class="health-services">${Object.entries(services).map(([key,service])=>{
 const heading=i18nHtml`<strong>${i18nT(serviceLabels[key])}</strong><span class="health-badge ${esc(service.status)}">${esc(labels[service.status])}</span>`;
 return i18nHtml`<li data-service="${esc(key)}"><div class="health-detail-heading">${heading}</div></li>`;
 }).join('')}</ul>`;
}
function marketplaceStatus(items){
 return i18nHtml`<section class="report-panel marketplace-status"><div class="report-section-title"><h2>Pazaryerlerinin durumu</h2><span>Bu ay</span></div>
  <div class="table-scroll"><table class="corporate-table"><thead><tr><th scope="col">Pazaryeri</th><th scope="col">Mağaza durumu</th><th scope="col">Sonraki sipariş kontrolü</th><th scope="col">İptaller Sonrası Ciro</th></tr></thead>
  <tbody>${items.map(item=>i18nHtml`<tr><th scope="row">${platform(item.channel)}</th><td>${i18nT(item.active?'Aktif':'Pasif')}</td>
  <td>${timestamp(item.nextRunAt)}</td><td>${Number.isSafeInteger(item.salesTurnoverCents)?money(item.salesTurnoverCents):'—'}</td></tr>`).join('')}</tbody></table></div>
 </section>`;
}
function reconciliationStatus(value,prefix){
 if(!value)return '';
 const status=value.state==='healthy'?i18nT('Kayıtlar eşleşiyor'):value.state==='issue'?i18nT('Fark bulundu'):i18nT('Tamamlanmış kontrol bekleniyor');
 const heading=i18nHtml`<strong>Sipariş mutabakatı</strong><small>${status}</small>`;
 return i18nHtml`<div class="health-detail-heading">${heading}</div>`;
}
export function healthShell(state){return i18nHtml`<section class="reporting" data-reporting="connections"><div class="health-result" aria-live="polite">${loadingState('Bağlantılar kontrol ediliyor…')}</div></section>`;}
export function healthResult(report,search=''){
 if(!report.company?.id)return i18nHtml('<p class="report-empty">Bağlantıları görmek için müşteri seçin.</p>');
 const q=search.toLocaleLowerCase('tr-TR'),items=report.items.filter(i=>Number(i.companyId)===Number(report.company.id)&&(i.channel+' '+i.storeName).toLocaleLowerCase('tr-TR').includes(q));
 if(!items.length)return i18nHtml('<p class="report-empty">Bu sayfada eşleşen bağlantı bulunmuyor.</p>');
 return i18nHtml`<p class="report-note">${esc(report.company.name)} · Son kontrol ${timestamp(report.checkedAt)}</p>
  ${healthChart(items)}
  <div class="health-grid">${items.map(i=>i18nHtml`<article class="health-card">
   <h3 class="health-marketplace-heading">${platform(i.channel)}</h3>
   <span class="health-badge ${i.active?esc(i.status):'inactive'}">${esc(labels[i.active?i.status:'inactive'])}</span>
   ${i.active?i18nHtml`<p>${esc(connectionMessage(i.message))}</p>`:''}
   <dl><div><dt>Mağaza</dt><dd>${esc(i.storeName)||i18nT('Ad eklenmemiş')}</dd></div>
   <div><dt>Son başarılı sipariş kontrolü</dt><dd>${timestamp(i.lastSuccessAt)}</dd></div>
   <div><dt>Veri başlangıcı</dt><dd>${timestamp(i.coverageFrom)}</dd></div></dl>
   ${serviceList(i.services,i.companyId+"-"+i.channel+"-")}${reconciliationStatus(i.reconciliation,i.companyId+"-"+i.channel+"-")}
  </article>`).join('')}</div>
  ${marketplaceStatus(items)}`;
}


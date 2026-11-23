import {paginationButtons} from './pagination-controls.js';
import {labelMap as localizedLabels} from './i18n.js';
import {loadingState} from './content-state.js';
import {currentLocale} from './i18n.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc,platform,money} from './ui.js';
export const returnProviders={hepsiburada:'Hepsiburada',n11:'n11',idefix:'idefix',pazarama:'Pazarama',koctas:'Koçtaş',pttavm:'PTT AVM',trendyol:'Trendyol'};
export const returnStatuses=localizedLabels({all:'Tüm durumlar',pending:'Bekleyen talepler',approved:'Onaylananlar',refunded:'Geri ödemesi tamamlananlar',rejected:'Reddedilenler',cancelled:'İptal edilen talepler',unknown:'Kontrol gerekenler'});
const time=(value,dateOnly=false)=>value&&Number.isFinite(Date.parse(value))?new Date(value).toLocaleString(currentLocale(),{timeZone:'Europe/Istanbul',dateStyle:'short',...(dateOnly?{}:{timeStyle:'short'})}):i18nT('Bildirilmedi');
function requestTime(row){
 if(!row.createdAt||!Number.isFinite(Date.parse(row.createdAt)))return esc(i18nT('Bildirilmedi'));
 const date=new Date(row.createdAt),options={timeZone:'Europe/Istanbul'};
 return i18nHtml`<time datetime="${esc(row.dateOnly?row.createdAt.slice(0,10):row.createdAt)}"><span>${esc(date.toLocaleDateString(currentLocale(),{...options,dateStyle:'short'}))}</span>${row.dateOnly?'':i18nHtml`<small>${esc(date.toLocaleTimeString(currentLocale(),{...options,timeStyle:'short'}))}</small>`}</time>`;
}
export function returnsContent(){return i18nHtml`<section class="returns-workspace" data-returns aria-label="İptal ve iade talepleri">${loadingState('Talepler yükleniyor…')}</section>`;}
const operationalSource=source=>source.coverage==='order_status_returns'&&source.state==='partial';
function sourceCard(source,cancellations){
 const labels={ready:i18nT('Son tarama tamamlandı'),partial:i18nT('Kısmi kapsam'),waiting:i18nT('İlk tam tarama bekleniyor'),stale:i18nT('Güncellik doğrulanamadı'),unavailable:i18nT('Veri bağlantısı yok')};
 const orderStatusDescription=cancellations?'İade ve göndericiye dönüş durumları sipariş kayıtlarından izlenir. İptal ve teslim edilemedi durumları ilgili listelere uygulanır.':'İade ve göndericiye dönüş durumları sipariş kayıtlarından izlenir.';
 return i18nHtml`<article class="return-source ${source.state==='ready'||operationalSource(source)?'':'return-source-warning'}"><strong>${esc(source.provider)}</strong><span>${esc(operationalSource(source)?i18nT('Sipariş durumları izleniyor'):labels[source.state]||i18nT('Kontrol gerekiyor'))}</span><details class="return-source-details"><summary>Aktarım ayrıntıları</summary><small>${source.coverage==='order_status_returns'?i18nT(orderStatusDescription):source.coverage==='completed_only'?i18nT('Yalnız siparişlere işlenmiş onaylı iadeler görünür. Bekleyen iade talepleri henüz bu bağlantıdan alınmıyor.'):source.coverage==='order_line_returns'?i18nT('Sipariş satırlarının bekleyen, onaylanan, reddedilen ve tamamlanan iade durumları. Kaynak ayrı talep tarihi veya tutarı bildirmiyor.'):i18nT('İade talep ve durum kayıtları.')}</small><small>Son başarılı aktarım: ${esc(time(source.lastSuccessAt))}</small>${source.from?i18nHtml`<small>Tarama başlangıcı: ${esc(time(source.from))}</small>`:''}</details></article>`;
}
export function returnsView(model){
 const data=model.data,disabled=model.loading?'disabled':'';
 const types=model.types??['all','cancellation','return'];
 const filters=i18nHtml`<form class="return-filters" data-return-filters>
  <label>Pazaryeri<select name="provider"><option value="all">Tüm pazaryerleri</option>${Object.entries(returnProviders).map(([key,label])=>i18nHtml`<option value="${key}" ${model.provider===key?'selected':''}>${label}</option>`).join('')}</select></label>
  <label>Talep türü<select name="type">${[['all','Tüm talepler'],['cancellation','İptal'],['return','İade']].filter(([key])=>types.includes(key)).map(([key,label])=>i18nHtml`<option value="${key}" ${model.type===key?'selected':''}>${i18nT(label)}</option>`).join('')}</select></label>
  <label>Durum<select name="status">${Object.entries(returnStatuses).map(([key,label])=>i18nHtml`<option value="${key}" ${model.status===key?'selected':''}>${label}</option>`).join('')}</select></label>
  <label>Sipariş, müşteri, talep veya ürün<input type="search" name="search" maxlength="180" value="${esc(model.search)}" placeholder="Listede ara"></label>
  <button class="btn primary" type="submit" ${disabled}>Listele</button>
 </form>`;
 const labels=['Pazaryeri','Talep türü','Sipariş no','Müşteri adı','Talep tarihi','Adet','Kaynak tutarı','Durum','Talep sebebi'];
 const rows=(data?.items||[]).map(row=>i18nHtml`<tr>
  <th scope="row"><span class="return-cell-label" aria-hidden="true">${i18nT(labels[0])}</span>${platform(row.provider)}</th><td><span class="return-cell-label" aria-hidden="true">${i18nT(labels[1])}</span>${i18nT(row.type==='cancellation'?'İptal':'İade')}</td>
  <td class="return-order-number"><span class="return-cell-label" aria-hidden="true">${i18nT(labels[2])}</span>${esc(row.orderNumber)}</td><td><span class="return-cell-label" aria-hidden="true">${i18nT(labels[3])}</span>${esc(row.customerName||i18nT('Bildirilmedi'))}</td>
  <td class="return-request-date"><span class="return-cell-label" aria-hidden="true">${i18nT(labels[4])}</span>${requestTime(row)}</td>
  <td><span class="return-cell-label" aria-hidden="true">${i18nT(labels[5])}</span>${row.quantity??'—'}</td><td><span class="return-cell-label" aria-hidden="true">${i18nT(labels[6])}</span>${row.amountCents==null?'—':money(row.amountCents)}</td>
  <td><span class="return-cell-label" aria-hidden="true">${i18nT(labels[7])}</span><span class="return-status ${esc(row.group)}">${esc(i18nT(row.statusLabel))}</span></td><td><span class="return-cell-label" aria-hidden="true">${i18nT(labels[8])}</span>${esc(row.reason||'—')}</td>
 </tr>`).join('');
 const pageSize=data?.pageSize??20,pages=Math.max(1,Math.ceil((data?.total??0)/pageSize));
 const feedback=model.error||(data?i18nText`${data.total} aktarılan talep kaydı`:i18nT('Talepler yükleniyor…'));
 const placeholder=model.loading?loadingState('Talepler yükleniyor…',{compact:true}):model.error?i18nT('Liste alınamadı. Yeniden deneyin.'):i18nT(data?.sources?.length?'Bu filtrede aktarılmış talep yok. Bağlantı kapsamı için aktarım ayrıntılarına bakabilirsiniz.':'Bu filtrede aktarılmış talep yok.');
 return i18nHtml`${filters}<p class="return-feedback ${model.error?'return-error':''}" role="status">${esc(feedback)}</p>
  <div class="return-table-wrap"><table class="return-table corporate-table" data-copyable-table>
   <colgroup>${[14,8,13,13,10,5,10,12,15].map(width=>i18nHtml`<col style="width:${width}%">`).join('')}</colgroup>
   <thead><tr>${labels.map(label=>i18nHtml`<th scope="col">${i18nT(label)}</th>`).join('')}</tr></thead>
   <tbody>${rows||i18nHtml`<tr><td colspan="${labels.length}">${placeholder}</td></tr>`}</tbody>
  </table></div>
  <nav class="return-pagination" aria-label="Talep tablosu sayfaları">
   <button class="btn" type="button" data-returns-page="${Math.max(0,model.page-1)}" ${disabled||!model.page?'disabled':''}>Önceki</button>
   ${paginationButtons({page:model.page,count:pages,attribute:'data-returns-page',disabled:model.loading})}
   <button class="btn" type="button" data-returns-page="${model.page+1}" ${disabled||!data?.hasMore?'disabled':''}>Sonraki</button>
  </nav>
  ${data?.sources?.length?i18nHtml`<details class="return-coverage"><summary>Aktarım kapsamı</summary><div class="return-sources">${data.sources.map(source=>sourceCard(source,types.includes('cancellation'))).join('')}</div></details>`:''}`;
}

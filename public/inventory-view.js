import {inventoryModelCapabilities} from './inventory-capabilities.js';
import {inventorySortControl} from './inventory-sorting.js';
import {inventoryPagination} from './inventory-pagination.js';
import {inventorySku} from './inventory-copy.js';
import {inventoryRowActions} from './inventory-row-actions.js';
import {esc,icon} from './ui.js';
import {inventoryProgressView} from './inventory-progress-view.js';
import {loadingState} from './content-state.js';
import {inventoryModel} from './inventory-model.js';
import {inventoryPriceCell} from './inventory-price-view.js';

const count=value=>Number(value||0).toLocaleString('tr-TR');
const quantity=value=>value==null?'—':count(value);
export function inventoryView(model){
 const capabilities=inventoryModelCapabilities(model),showPrice=capabilities.viewPurchasePrice,showActions=['viewMovements','viewSales','viewMarketplaceStocks','editStock','recordStoreSale'].some(action=>capabilities[action]),data=model.data,items=data?.items||[],mode=data?.settings?.mode||'marketplace',layout=inventoryModel(mode,data?.settings),{store,online,horoz,showStore,showTotal,explanation}=layout;
 return `${inventoryProgressView(data,model.admin)}<div class="inventory-toolbar"><form data-inventory-search><label>Ürün ara<input name="query" value="${esc(model.query)}" placeholder="Ürün adı, stok kodu veya barkod" autocomplete="off"></label>${categoryFilter(model)}${marketplaceFilter(model)}${inventorySortControl(model)}${filters(model)}<button type="submit" class="btn secondary" ${model.busy?'disabled':''}>Ara</button></form>${store&&(capabilities.viewMovements||model.admin)?`<div class="inventory-tools">${capabilities.viewMovements?'<button type="button" class="btn secondary" data-stock-history-all>Stok hareketleri</button>':''}${model.admin?'<button type="button" class="btn" data-manual-add>Manuel ürün ekle</button>':''}</div>`:''}<div class="inventory-total"><strong>${count(data?.total)}</strong> ürün<small>${layout.summary}</small></div></div>
 <details class="muted"><summary>Stok nasıl hesaplanır?</summary><p>${explanation}</p>${data?.skuOnly?identityCoverage(data.pendingSkuListings,true,true):data?.barcodeOnly?identityCoverage(data.pendingBarcodeListings,false,true):''}</details>
 ${data?.skuConflicts?`<p>${count(data.skuConflicts)} stok kodunda ürün bilgileri farklı · <button type="button" class="text-btn" data-conflict-records>Kayıtları göster</button></p>`:''}
 ${model.error?`<p role="alert" class="inventory-warning">${esc(model.error)}</p>`:''}
 ${data?.issues?`<p class="inventory-warning">${count(data.issues)} siparişin ürün/adet eşleştirmesi kontrol bekliyor. Satış istatistiklerinde eksik olabilir; doğrulanan stok bilgileri ayrıca gösterilir.</p>`:''}
 ${data?.persistentError&&data?.sync?.error?`<p class="inventory-warning">${esc(data.sync.error)}</p>`:''}
 ${data?.pendingProducts?.length?`<details class="inventory-warning"><summary>Katalog eşleştirmesi bekleyen ${count(data.pendingProducts.length)} ürün</summary><p>Bu ürünler siparişlerde bulundu. Katalogla eşleştirilene kadar canlı stok doğrulanamaz. ${horoz?'Sipariş hareketleri canlı stoğa ikinci kez uygulanmaz.':'Siparişler mağaza stoğundan düşülmez.'}</p><div class="inventory-pending-list">${data.pendingProducts.map(p=>`<div><span><strong>${esc(p.title)}</strong><small>${esc(p.sku)} · ${count(p.quantity)} adet sipariş</small></span><button type="button" class="btn secondary" data-pending-product="${esc(p.key)}">Listede göster</button></div>`).join('')}</div></details>`:''}
 ${selectionBar(model,items)}<div class="inventory-table-wrap" tabindex="0" aria-label="Stok yönetimi ürünleri"><table class="inventory-table" role="table" data-stock-columns="${layout.stockColumns}" data-stock-model="${mode}"><thead role="rowgroup"><tr role="row">${model.admin?'<th scope="col" class="inventory-selection-cell">Seç</th>':''}<th scope="col" class="inventory-image-column">Görsel</th><th scope="col" class="inventory-product-column">Ürün / stok kodu</th>${online?'<th scope="col">'+(horoz?'Stok':'Pazaryeri stoğu')+'</th>':''}${showStore?'<th scope="col">Mağaza stoğu</th>':''}${showTotal?'<th scope="col">Toplam stok</th>':''}${showPrice?'<th scope="col" class="inventory-price-column">Alış fiyatı</th>':''}${showActions?'<th scope="col" class="inventory-actions-column">İşlemler</th>':''}</tr></thead><tbody role="rowgroup">${items.map(item=>row(item,model.admin,{...layout,capabilities,showPrice,showActions},model.selected?.has(item.key),model.busy)).join('')||`<tr role="row" class="inventory-empty-row"><td role="cell" colspan="${2+layout.stockColumns+Number(model.admin)+Number(showPrice)+Number(showActions)}">${model.loading||model.busy?loadingState('Ürünler yükleniyor…',{compact:true}):model.query||model.category?'Seçtiğiniz filtrelere uygun ürün bulunmuyor.':model.filter==='stocked'?'Stoklu ve satışa açık olduğu doğrulanmış ürün yok. Diğer kayıtlar için Tüm ürünler seçeneğini kullanın.':'Bu görünümde ürün bulunmuyor.'}</td></tr>`}</tbody></table></div>
 <div class="inventory-footer"><span class="muted">${model.loading||model.busy?'Güncelleniyor…':data?.sync?.lastSuccess?`Son kontrol: ${esc(new Date(data.sync.lastSuccess).toLocaleString('tr-TR'))}`:'İlk kontrol bekleniyor.'}</span>${inventoryPagination(model)}</div>
 <details class="inventory-coverage"><summary>Kapsam ve hesaplama</summary><p>Net satış adedi = sipariş − iptal − onaylı iade. Ortalama fiyat, tutarı doğrulanmış siparişlerin birim fatura tutarıdır; hakediş veya kâr değildir. Bu istatistikler stoktan ikinci kez düşülmez.</p><p>Ürün görselleri elle eklenebilir ve kaldırılabilir. Aktif listede güncel pozitif stoğu olan ve en az bir pazaryerinde satışa açık olduğu doğrulanan ürünler görünür. Satışa kapalı, stok dışı veya satış durumu doğrulanamayan ürünler Tüm ürünler bölümünde kalır. Yeniden stoklu ve satışa açık geldiğinde ürün onay beklemeden döner. Yalnız manuel takip edilen ürünlerde mağaza stoğu esas alınır. Bilerek silinen ürünler tekrar aktarılmaz; yalnız Silinen ürünler bölümünden geri alınır. Stoklar farklıysa güncel bir pazaryeri esas alınır ve fark notu gösterilir. Güncel pazaryeri stoğu veya mağaza sayımı yoksa toplam hesaplanmaz.</p></details>`;
}
function row(item,admin,{store,online,horoz,showStore,showTotal,capabilities,showPrice,showActions},selected,busy){
 const active=item.links?.filter(x=>x.state==='active')||[];
 const tracking=item.manual?`<small class="${active.length?'inventory-live':'inventory-history-note'}">${active.length?active.length+' pazaryerinde canlı':'Canlı bağlantı yok · Manuel ürün'}</small>${(item.links||[]).filter(x=>x.reason).map(x=>`<small class="inventory-history-note">${esc(x.provider)}: ${esc(x.reason)}</small>`).join('')}`:'';
 return `<tr role="row" class="inventory-product-row${admin?' inventory-product-selectable':''}">
  ${admin?`<td role="cell" class="inventory-selection-cell"><input type="checkbox" data-stock-select="${esc(item.key)}" aria-label="${esc(item.title)} ürününü seç" ${selected?'checked':''} ${item.pending||busy?'disabled':''}></td>`:''}
  <td role="cell" class="inventory-image-cell">${productImage(item,admin)}</td>
  <td role="cell" class="inventory-product-cell"><strong class="inventory-product">${esc(item.title)}</strong>${inventorySku(item.sku)}${item.barcode?`<small class="muted">Barkod: ${esc(item.barcode)}</small>`:''}${item.removed?'<small class="inventory-history-note">Tablodan silindi</small>':''}${tracking}${!item.manual&&item.eligibility?.status==='review'?'<small class="inventory-history-note">Stok geçmişi doğrulanamadı</small>':''}</td>
  ${online?`<td role="cell" class="inventory-stock-cell"><span class="inventory-mobile-label" aria-hidden="true">${horoz?'Stok':'Pazaryeri stoğu'}</span>${sourceStock(item,horoz)}</td>`:''}
  ${showStore?`<td role="cell" class="inventory-stock-cell"><span class="inventory-mobile-label" aria-hidden="true">Mağaza stoğu</span><strong class="inventory-quantity">${quantity(item.storeQuantity)}</strong><small class="inventory-unit">adet</small>${item.storeQuantity===null?'<small>Sayım bekliyor</small>':''}${item.pending?'<small class="inventory-history-note">Kayıt sırada</small>':''}</td>`:''}
  ${showTotal?`<td role="cell" class="inventory-stock-cell inventory-total-cell"><span class="inventory-mobile-label" aria-hidden="true">Toplam stok</span><strong class="inventory-quantity ${item.critical?'is-critical':''}">${quantity(item.totalQuantity)}</strong><small class="inventory-unit">adet</small>${item.totalQuantity==null?'<small>Bilgi doğrulanmalı</small>':item.critical?'<small>Stok tükendi</small>':''}</td>`:''}
  ${showPrice?inventoryPriceCell(item,{editable:capabilities.editPurchasePrice}):''}${showActions?'<td role="cell" class="inventory-actions-cell">'+inventoryRowActions(item,admin,{store,online,horoz,capabilities})+'</td>':''}
 </tr>`;
}
function sourceStock(item,horoz){
 return `<strong class="inventory-quantity">${quantity(horoz?item.quantity:item.marketplaceQuantity)}</strong><small class="inventory-unit">adet</small>${horoz&&Number.isSafeInteger(item.marketplaceQuantity)&&Number.isSafeInteger(item.quantity)&&item.marketplaceQuantity!==item.quantity?`<small class="inventory-stock-comparison" role="note">Pazaryeri: ${quantity(item.marketplaceQuantity)} adet<br>Manuel işlem sonrası: ${quantity(item.quantity)} adet</small>`:''}`;
}

function filters(model){
 const options=[['stocked','Stoklu ve satışa açık'],['depleted','Stoğu bitenler'],['all','Tüm ürünler']];
 if(model.data?.skuOnly||model.data?.barcodeOnly)options.push(['legacy','Eşleşmemiş eski kayıtlar']);
 if(model.admin)options.push(['removed','Silinen ürünler']);
 return '<label class="inventory-filter inventory-visibility-filter">Görünüm<select name="filter" '+(model.busy?'disabled':'')+'>'+options.map(([value,label])=>'<option value="'+value+'" '+(model.filter===value?'selected':'')+'>'+label+'</option>').join('')+'</select></label>';
}

function identityCoverage(rows=[],sku=false,expanded=false){
 const pending=rows.map(row=>esc(row.provider)+' '+count(row.count)).join(' · ');
 const field=sku?'stok kodu':'kaynak barkodu';
 const method=sku?'stok kodu ve doğrulanmış ürün eşleşmelerine göre':'kaynak barkoduna göre';
 return `<p class="${expanded?'muted':'muted inventory-explanation'}">Canlı ürünler ${method} tek satırda gösterilir. ${pending?'Son kontrollerde '+field+' bekleyen ilanlar · '+pending+'. ':''}Önceki eşleşmemiş kayıtları Görünüm menüsünden açabilirsiniz.</p>`;
}

function selectionBar(model,items){
 if(!model.admin)return '';
 const eligible=items.filter(item=>!item.pending),selected=eligible.filter(item=>model.selected?.has(item.key)).length;
 return '<div class="inventory-selection-bar"><label><input type="checkbox" data-stock-select-page '+(eligible.length&&selected===eligible.length?'checked':'')+' '+(model.busy||!eligible.length?'disabled':'')+'>Bu sayfadaki ürünleri seç</label><span role="status">'+selected+' ürün seçili</span><button type="button" class="btn secondary" data-stock-bulk '+(model.busy||!selected?'disabled':'')+'>'+(model.filter==='removed'?'Seçilenleri geri al':'Ürünleri sil')+'</button></div>';
}

function productImage(item,admin){
 const editable=admin&&!item.removed;
 if(!item.imageUrl&&!editable)return '<div class="inventory-image">'+icon('inventory')+'<small>Görsel yok</small></div>';
 return '<button type="button" class="inventory-image" data-stock-image="'+esc(item.key)+'" aria-label="'+esc(item.title)+' görselini '+(editable?'düzenle':'görüntüle')+'">'+(item.imageUrl?'<img src="'+esc(item.imageUrl)+'" alt="'+esc(item.title)+'" loading="lazy">':icon('inventory')+'<small>Görsel ekle</small>')+'</button>';
}

function marketplaceFilter(model){
 const options=(model.data?.providers||[]).map(provider=>'<option value="'+esc(provider)+'" '+(model.provider===provider?'selected':'')+'>'+esc(provider)+'</option>').join('');
 return '<label class="inventory-filter inventory-provider-filter">Pazaryeri<select name="provider" '+(model.busy?'disabled':'')+'><option value="">Tüm pazaryerleri</option>'+options+'</select></label>';
}

function categoryFilter(model){
 const options=(model.data?.categories||[]).map(category=>'<option value="'+esc(category.value)+'" '+(model.category===category.value?'selected':'')+'>'+esc(category.label)+'</option>').join('');
 return '<label class="inventory-filter inventory-category-filter">Ürün filtreleme<select name="category" '+(model.busy?'disabled':'')+'><option value="">Tüm Ürünler</option>'+options+'</select></label>';
}

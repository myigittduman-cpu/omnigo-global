import {esc,platform} from './ui.js';
import {tableCopyButton,tableCopyLine} from './table-copy-button.js';
import {productMarketplaces} from './product-marketplaces.js';
import {orderDate} from './delivery-view.js';
import {paginationButtons} from './pagination-controls.js';

export function stockProviderOptions(providers,current){
 const choices=new Set([...(providers||[]),...(current?[current]:[])]);
 return '<option value="">Tüm pazaryerleri</option>'+[...choices].map(name=>`<option value="${esc(name)}" ${name===current?'selected':''}>${esc(name)}</option>`).join('');
}

export function stockListingsShell(model){
 return `<form class="orders-filters" data-stock-list-filters role="search">
  <label class="orders-search">Ürün ara<input type="search" name="query" maxlength="120" placeholder="Satıcı stok kodu veya ürün adı" value="${esc(model.query)}"></label>
  <label>Pazaryeri<select name="provider">${stockProviderOptions(model.data?.providers,model.provider)}</select></label>
  <button type="submit" class="btn">Filtrele</button></form>
 <p class="orders-feedback" data-stock-list-status role="status"></p>
 <div class="orders-table-wrap corporate-table-viewport" data-stock-list-frame data-page-frame tabindex="0" aria-label="Pazaryeri stokları tablosu">
  <table class="corporate-table pilot-stock-table" data-copyable-table><thead><tr>
   <th scope="col">Satıcı stok kodu</th><th scope="col">Ürün adı</th><th scope="col">Pazaryeri</th><th scope="col">Stok</th><th scope="col">Satış fiyatı (₺)</th><th scope="col">Son güncelleme</th><th scope="col">İşlem</th>
  </tr></thead><tbody></tbody></table>
 </div><nav class="orders-footer" data-stock-list-pager aria-label="Pazaryeri stok sayfaları"></nav>`;
}

function copyControl(content,value,label){
 return tableCopyLine(content,value?tableCopyButton({label,data:{'listing-copy':value}}):'');
}
function showStockValue(value,mixed){
 if(value)return esc(value);
 const reason=mixed?'Pazaryeri değerlerini Düzenle penceresinden inceleyin.':'Kaynak değer henüz doğrulanmadı.';
 return `<span title="${esc(reason)}">—</span>`;
}

export function stockListingRows(items){
 return items.map(item=>{
  const stock=Number.isSafeInteger(item.quantity)?String(item.quantity):'';
  const price=Number.isSafeInteger(item.priceCents)?(item.priceCents/100).toLocaleString('tr-TR',{minimumFractionDigits:2}):'';
  const name=item.title||'Ürün adı bulunamadı';
  const picture=item.imageUrl?`<img src="${esc(item.imageUrl)}" alt="" width="48" height="48" loading="lazy">`:'<small>Görsel yok</small>';
  const providers=item.providers??[item.provider];
  return `<tr data-listing="${esc(item.cardKey??item.key)}"><td>${copyControl(`<strong>${esc(item.sku||'—')}</strong>`,item.sku,'Stok kodunu kopyala')}</td>
   <td><div class="listing-product">${picture}${copyControl(esc(name),item.title,'Ürün adını kopyala')}</div></td>
   <td>${providers.length===1?platform(providers[0]):productMarketplaces(providers)}</td>
   <td>${showStockValue(stock,item.mixedQuantity)}</td>
   <td>${showStockValue(price,item.mixedPrice)}</td>
   <td>${esc(item.observedAt?orderDate(item.observedAt):'—')}</td>
   <td><button type="button" class="btn" data-listing-edit>Düzenle</button></td></tr>`;
 }).join('');
}

export function stockListingPages({page,pageSize,total}){
 const count=Math.ceil(total/pageSize);
 return count>1?paginationButtons({page,count,attribute:'data-stock-list-page'}):'';
}

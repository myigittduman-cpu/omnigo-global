import {esc} from './ui.js';
import {marketplaceHistoryCell} from './inventory-marketplace-history.js';

const names={initialize:'Başlangıç mağaza stoğu',manual_create:'Manuel ürün oluşturma',store_sale:'Mağaza satışı',store_return:'Mağaza satış iadesi',set:'Mağaza / depo stok güncellemesi',add:'Stok girişi',subtract:'Stok düzeltmesi',order_sale:'Sipariş hareketi',order_restore:'İptal / iade hareketi',horoz_initialize:'Başlangıç ortak stok',horoz_set:'Ortak stok güncellemesi',horoz_add:'Ortak stok girişi',horoz_subtract:'Ortak stok düzeltmesi',horoz_sale:'Mağaza satışı · Ortak stok',horoz_return:'Mağaza satış iadesi · Ortak stok'};
const count=value=>value==null?'—':Number(value).toLocaleString('tr-TR');
const date=value=>new Date(value).toLocaleString('tr-TR',{timeZone:'Europe/Istanbul'});

export function inventoryHistoryView(data,{product=false,page=0}={}){
 const rows=data.items||[];
 if(!rows.length)return '<p class="muted">Bu tarih aralığında stok hareketi yok.</p>';
 return `<div class="inventory-history-table-wrap" tabindex="0" aria-label="Stok hareketleri tablosu"><table class="inventory-history-table"><thead><tr><th>Tarih / saat</th><th>Kullanıcı</th>${product?'':'<th>Ürün / stok kodu</th>'}<th>İşlem</th><th>Önceki stok</th><th>Adet değişimi</th><th>Son stok</th></tr></thead><tbody>${rows.map(row=>historyRow(row,{product,writable:data.marketplaceManagement})).join('')}</tbody></table></div><nav class="inventory-history-pages" aria-label="Stok hareketleri sayfaları"><button type="button" class="btn secondary" data-history-prev ${page===0?'disabled':''}>Önceki</button><span class="pagination-current" aria-current="page">Sayfa ${page+1}</span><span>${rows.length} hareket</span><button type="button" class="btn secondary" data-history-next ${data.nextCursor?'':'disabled'}>Sonraki</button></nav>`;
}
function historyRow(row,{product,writable}){
 const request=row.marketplaceRequest;
 return `<tr${request?` data-marketplace-request="${esc(request.id)}"`:''}><td>${esc(date(row.created))}</td><td>${esc(row.actor_name||'Kullanıcı bilgisi yok')}</td>${product?'':`<td><strong>${esc(row.title||'Ürün kaydı')}</strong><small>${esc(row.sku||'')}</small></td>`}<td${request?' data-marketplace-history-state':''}>${request?marketplaceHistoryCell(request,{writable}):esc(names[row.kind]||'Stok hareketi')}</td><td>${count(row.quantity_before)}</td><td><strong>${Number(row.delta)>0?'+':''}${count(row.delta)}</strong></td><td${request?' data-marketplace-history-after':''}>${count(row.quantity_after)}</td></tr>`;
}

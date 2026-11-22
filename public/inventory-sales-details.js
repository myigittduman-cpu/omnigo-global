import {esc,money,openDialog} from './ui.js';

export function inventorySalesDetails(item){
 const count=value=>Number(value||0).toLocaleString('tr-TR'),visibility=item.sensitiveVisibility||{cancellations:true,returns:true};
 const markets=new Map((item.markets||[]).map(m=>[m.provider,m]));
 const availability=new Map((item.marketplaceAvailability||[]).map(value=>[value.provider,value]));
 const providers=[...new Set([...availability.keys(),...(item.listedOn||[]),...markets.keys()])];
 const rows=providers.map(provider=>{
  const market=markets.get(provider)||{},state=availability.get(provider)||{status:'unknown',label:'Kontrol bekliyor',detail:'Satış durumu henüz doğrulanamadı.'};
  return `<tr><th scope="row">${esc(provider)}</th><td>${count(market.gross)}</td>${visibility.cancellations?`<td>${count(market.cancelled)}</td>`:''}${visibility.returns?`<td>${count(market.returned)}</td>`:''}<td>${count(market.net)}</td><td>${market.averageInvoiceCents==null?'—':money(market.averageInvoiceCents)}</td><td><span class="inventory-sale-state inventory-sale-state--${esc(state.status)}" title="${esc(state.detail)}">${esc(state.label)}</span></td></tr>`;
 }).join('')+`<tr><th scope="row">Mağaza</th><td>${count(item.storeSales)}</td>${visibility.cancellations?'<td>—</td>':''}${visibility.returns?'<td>—</td>':''}<td>${count(item.storeSales)}</td><td>—</td><td>Manuel satış</td></tr>`;
 return `<p><strong>${esc(item.title)}</strong></p><p class="muted">${visibility.cancellations&&visibility.returns?'Pazaryeri satışları, iptal ve onaylı iadeler ile elle kaydedilen mağaza satışları.':'Pazaryeri ve mağaza satışlarının dağılımı.'} Ortalama fatura tutarı, doğrulanmış siparişlerin birim tutarıdır.</p><div class="inventory-stock-details-wrap" tabindex="0" aria-label="Satış detayları; diğer sütunlar için yana kaydırın"><table class="inventory-stock-details"><thead><tr><th>Pazaryeri</th><th>Satış</th>${visibility.cancellations?'<th>İptal</th>':''}${visibility.returns?'<th>İade</th>':''}<th>Net</th><th>Ort. fatura</th><th>Satış / eşleşme durumu</th></tr></thead><tbody>${rows||'<tr><td colspan="7">Henüz eşleşmiş sipariş yok.</td></tr>'}</tbody></table></div><p class="form-help inventory-sale-legend">Ürün bulunamadı: son katalogda eşleşen kayıt yok. Eşleşme bekliyor: aynı kodda başka bir ürün kaydı var. Kaynağın doğrulamadığı satış durumu açık veya kapalı varsayılmaz.</p>`;
}

export function openInventorySalesDetails(item){
 return openDialog('Satış detayları',inventorySalesDetails(item),{className:'inventory-dialog inventory-stock-dialog inventory-sales-dialog'});
}

import {esc,openDialog} from './ui.js';

function panelCheck(stock){
 const proof=stock.idefixStockCheck;
 if(proof)return `<small>Son panel teyidi · ${esc(proof.panelLabel)} · ${esc(new Date(proof.panelAt).toLocaleString('tr-TR'))}</small>`;
 const check=stock.lastPanelCheck;
 if(!check?.observedAt)return '';
 const when=new Date(check.observedAt).toLocaleString('tr-TR');
 return `<small>Son panel teyidi · ${esc(check.label)} · ${esc(when)}${check.current?'':' · Yeniden doğrulama bekliyor'}</small>`;
}

const quantity=value=>value==null?'—':Number(value).toLocaleString('tr-TR');

function stockQuantity(stock){
 const proof=stock.idefixStockCheck;
 if(!proof)return quantity(stock.quantity);
 const panel=`<small>Panel · ${quantity(proof.panelQuantity)} adet · ${esc(new Date(proof.panelAt).toLocaleString('tr-TR'))}</small>`;
 const latest=proof.inventoryAt
  ?`<strong>${quantity(proof.inventoryQuantity)} adet</strong><small>Envanter API · ${esc(new Date(proof.inventoryAt).toLocaleString('tr-TR'))}</small>`:'';
 return `${latest}${panel}<small>Ürün havuzu · ${quantity(stock.quantity)}</small>`;
}

export function openMarketplaceStockDetails(item){
 const rows=(item.marketplaceStocks||[]).map(stock=>`<tr><th scope="row">${esc(stock.provider)}</th><td>${esc(stock.sku||'—')}</td><td>${stockQuantity(stock)}</td><td>${stock.saleActive===true?'Satışa açık':stock.saleActive===false?'Satışa kapalı':'Doğrulama bekliyor'}${panelCheck(stock)}</td><td>${stock.health==='current'?'Güncel':'Doğrulama bekliyor'}<small>${stock.observedAt?esc(new Date(stock.observedAt).toLocaleString('tr-TR')):'Henüz kontrol edilmedi'}</small></td></tr>`).join('');
 return openDialog('Pazaryeri stok detayları',`<p><strong>${esc(item.title)}</strong></p><p class="muted">Her pazaryerinin son bildirdiği stok ve satış durumu. Ortak stok adetleri birbirine eklenmez.</p><div class="inventory-stock-details-wrap" tabindex="0" aria-label="Pazaryeri stok detayları; diğer sütunlar için yana kaydırın"><table class="inventory-stock-details"><thead><tr><th>Pazaryeri</th><th>Stok kodu</th><th>Adet</th><th>Satış durumu</th><th>Son kontrol</th></tr></thead><tbody>${rows}</tbody></table></div>`,{className:'inventory-dialog inventory-stock-dialog'});
}

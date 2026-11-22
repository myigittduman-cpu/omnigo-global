import {esc,icon} from './ui.js';

export function inventoryRowActions(item,admin,{store,online,horoz=false,capabilities=null}){
 const allowed=action=>admin||capabilities?.[action]!==false;
 const key=esc(item.key),disabled=item.pending?'disabled':'';
 const stockQuantity=horoz?item.quantity:item.storeQuantity;
 const icons={'stock-edit':'inventory','stock-history':'reports','stock-sales':'analytics','stock-sources':'channels','store-sale':'plus','stock-matching':'connections','marketplace-stock':'channels'};
 const button=(action,label,attrs='')=>`<button type="button" class="btn secondary inventory-action${action==='stock-sources'?' inventory-action-wide':''}" data-${action}="${key}" ${attrs}><span class="inventory-action-icon">${icon(icons[action])}</span><span>${label}</span></button>`;
 const actions=[];
 if((admin||capabilities?.editStock)&&!item.removed&&store)actions.push(button('stock-edit',!horoz&&stockQuantity===null?'Mağaza stoğu gir':'Stoğu düzenle',disabled));
 if(allowed('viewMovements'))actions.push(button('stock-history','Hareketler'));
 if(allowed('viewSales'))actions.push(button('stock-sales','Satış detayları'));
 if(allowed('viewMarketplaceStocks')&&online&&item.marketplaceStocks?.length)actions.push(button('stock-sources','Pazaryeri stok detayları'));
 if((admin||capabilities?.recordStoreSale)&&!item.removed&&store)actions.push(button('store-sale','Mağaza satışı',stockQuantity===null?`disabled title="${horoz?'Önce güncel Horoz stoğunu doğrulayın':'Önce mağaza başlangıç stoğunu girin'}"`:disabled));
 if(admin&&!item.removed&&online&&item.manual)actions.push(button('stock-matching','Pazaryerleri ile eşleştir'));
 if(admin&&!item.removed&&item.marketplaceStockTargets?.length)actions.push(button('marketplace-stock','Pazaryeri stoğunu güncelle'));
 return `<div class="inventory-row-actions" data-action-count="${actions.length}">${actions.join('')}</div>`;
}

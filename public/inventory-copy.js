import {esc} from './ui.js';

export function inventorySku(sku){
 if(!sku)return '<div class="inventory-sku">Stok kodu yok</div>';
 return `<div class="inventory-sku"><span>${esc(sku)}</span></div>`;
}

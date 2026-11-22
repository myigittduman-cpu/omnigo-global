import {esc} from './ui.js';
export const inventoryPriceLabel=cents=>cents==null?'Fiyat gir':new Intl.NumberFormat('tr-TR',{style:'currency',currency:'TRY'}).format(cents/100);
export const grossPurchasePrice=item=>item.purchaseGrossCents??item.purchasePriceCents;
export function inventoryPriceCell(item,{editable=true}={}){
 const gross=grossPurchasePrice(item);
 const label=inventoryPriceLabel(gross);
 const control=item.removed||!editable?esc(gross==null?'—':label):`<span class="inventory-price-slot"><button type="button" class="btn secondary inventory-price-button" data-purchase-price="${esc(item.key)}" aria-label="${esc(item.title)} alış fiyatını düzenle">${esc(label)}</button></span>`;
 return `<td role="cell" class="inventory-price-cell"><span class="inventory-mobile-label" aria-hidden="true">Alış fiyatı</span>${control}</td>`;
}

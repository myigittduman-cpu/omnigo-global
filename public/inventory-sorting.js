import {esc} from './ui.js';
export const inventorySortOptions={stable:'Sabit kayıt sırası',sku:'Stok koduna göre',name_asc:'Ürün adı A–Z',name_desc:'Ürün adı Z–A',category:'Ürün grubuna göre'};
export const inventorySortName=value=>Object.hasOwn(inventorySortOptions,value)?value:'stable';
export function inventorySortControl(model){return `<label class="inventory-filter inventory-sort-filter">Sıralama<select name="sort">${Object.entries(inventorySortOptions).map(([value,label])=>`<option value="${value}" ${inventorySortName(model.sort)===value?'selected':''}>${esc(label)}</option>`).join('')}</select></label>`;}

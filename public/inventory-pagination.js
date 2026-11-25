import {paginationButtons} from './pagination-controls.js';
import {esc} from './ui.js';

export function inventoryPageSize(value){
 return Number(value)===50?50:10;
}

export function inventoryPagination(model){
 const size=inventoryPageSize(model.pageSize),total=Number(model.data?.total||0);
 const pages=Math.max(1,Math.ceil(total/size)),page=model.page||0;
 const disabled=model.busy?'disabled':'';
 return `<nav class="inventory-pagination" aria-label="Stok ürünleri sayfaları" aria-busy="${Boolean(model.loading)}">
  <label class="inventory-page-size">Sayfa başına ürün<select data-inventory-page-size aria-label="Sayfa başına ürün" ${disabled}>${[10,50].map(value=>`<option value="${value}" ${size===value?'selected':''}>${value}</option>`).join('')}</select></label>
  <div class="inventory-page-controls"><button type="button" class="btn" data-inventory-page="${page-1}" ${model.busy||page===0?'disabled':''}>Önceki</button>${paginationButtons({page,count:pages,attribute:"data-inventory-page",disabled:model.busy})}<span aria-live="polite">Sayfa ${esc(page+1)} / ${pages}</span><button type="button" class="btn" data-inventory-page="${page+1}" ${model.busy||page+1>=pages?'disabled':''}>Sonraki</button></div>
 </nav>`;
}

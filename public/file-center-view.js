import {html,t,currentLocale} from './i18n.js';
import {esc,icon,platform} from './ui.js';
import {paginationButtons} from './pagination-controls.js';
import {selectionHeader,selectionCell} from './file-center-selection.js';
import {documentOutputActions} from './document-output-actions.js';
import {tableCopyButton,tableCopyLine} from './table-copy-button.js';

const statuses={
 active:'Satışa açık',closed:'Satışa kapalı',not_found:'Bu pazaryerine açılmamış',
 unmatched:'Ürün eşleşmesi bekliyor',unknown:'Satış durumu doğrulanamadı',
 awaiting:'Katalog aktarımı bekleniyor',out_of_stock:'Stok yok',unknown_stock:'Stok doğrulanamadı',
};
const categories={orders:'Sipariş listesi',stock:'Stok çıktısı',finance:'Finans raporu'};
export const statusLabel=value=>t(statuses[value]||statuses.unknown);
export const priceLabel=value=>t(value==='catalogue'?'Güncel katalog fiyatı':'Son satış fiyatı');
const time=value=>{
 const date=new Date(value);
 return value&&!Number.isNaN(date.valueOf())?new Intl.DateTimeFormat(currentLocale(),{
  day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit',timeZone:'Europe/Istanbul',
 }).format(date):'—';
};

function pagination({page,pageSize,total}){
 const count=Math.max(1,Math.ceil(total/pageSize));
 return html`<div class="file-center-pages"><span>${total} kayıt</span><div>
  <button class="btn" type="button" data-file-center-page="${page-1}" ${page===0?'disabled':''}>Önceki</button>
  ${paginationButtons({page,count,attribute:'data-file-center-page'})}
  <button class="btn" type="button" data-file-center-page="${page+1}" ${page>=count-1?'disabled':''}>Sonraki</button>
 </div></div>`;
}

function marketCell(value){
 const listed=value?.listed===true;
 const label=listed?t('Katalogda var'):value?.status==='not_found'?t('Katalogda yok'):t('Katalog kaydı doğrulanmadı');
 return html`<td class="file-market-cell"><span class="file-market-state" data-listed="${listed}" role="img" aria-label="${esc(label)}">${listed?'✓':'×'}</span></td>`;
}

function productCode(item){
 const order=String(item.latestOrderNumber??'').trim();
 const copy=tableCopyButton({
  label:t(order?'Son sipariş numarasını kopyala':'Bu ürün için aktarılmış sipariş bulunamadı'),
  data:{'file-center-copy-order':order},disabled:!order,
 });
 return tableCopyLine(html`<small>${esc(item.sku||item.barcode||'—')}</small>`,copy);
}

export function fileCenterProducts(data){
 const providers=data.providers||[];
 const waiting=providers.filter(provider=>!provider.ready);
 const notes=waiting.length?html`<p class="file-center-coverage">Katalog kontrolü bekleniyor · ${esc(waiting.map(x=>x.name).join(', '))}</p>`:'';
 const fallback=t('Seçili filtrelere uygun ürün bulunamadı.');
 return html`${notes}<div class="corporate-table-viewport file-center-table" tabindex="0" aria-label="Pazaryeri ürünleri">
  <table class="corporate-table" data-copyable-table><thead><tr>${selectionHeader()}<th scope="col" class="file-center-product-column">Ürün</th>
   ${providers.map(provider=>html`<th scope="col" class="file-market-cell"><span class="file-market-title">${platform(provider.name)}</span></th>`).join('')}
  </tr></thead><tbody>${data.items.map(item=>html`<tr data-file-center-product="${esc(item.key)}">${selectionCell(item.key,item.sku||item.name)}<td>
   <div class="file-product">
    ${item.imageUrl?html`<img src="${esc(item.imageUrl)}" alt="" loading="lazy" decoding="async" width="52" height="52">`:
     html`<span class="file-product-empty-photo" title="Görsel bekleniyor">${icon('inventory')}</span>`}
    <div class="file-product-details"><strong>${esc(item.name)}</strong>${productCode(item)}</div>
   </div>
  </td>${providers.map(provider=>marketCell(item.markets?.[provider.name])).join('')}</tr>`).join('')||
   html`<tr><td colspan="${providers.length+2}">${esc(fallback)}</td></tr>`}
  </tbody></table>
 </div>${pagination(data)}`;
}

function archiveActions(file){
 const download={attr:'data-file-center-download',value:file.id};
 if(file.type==='xlsx')return documentOutputActions({excel:download});
 if(file.type!=='pdf')return '';
 const output={pdf:download};
 if(Object.hasOwn(categories,file.category)){
  output.print={attr:'data-archive-print',value:file.id};
  output.share={attr:'data-archive-share',value:file.id};
 }
 return documentOutputActions(output);
}

export function fileCenterArchive(data){
 return html`<div class="corporate-table-viewport file-center-table" tabindex="0" aria-label="Hazırlanan dosyalar">
  <table class="corporate-table" data-copyable-table>
   <thead><tr>${selectionHeader()}<th scope="col">Dosya</th><th scope="col">Tür</th><th scope="col">Hazırlanma tarihi</th><th scope="col">İşlemler</th></tr></thead>
   <tbody>${data.items.map(file=>html`<tr>${selectionCell(file.id,file.name)}<td class="file-center-filename"><strong>${esc(file.name)}</strong></td>
     <td>${t(categories[file.category])}</td><td>${esc(time(file.createdAt))}</td>
     <td>${archiveActions(file)}</td></tr>`).join('')||
    html`<tr><td colspan="5">Bu mağaza için hazırladığınız dosyalar burada görünecek.</td></tr>`}
   </tbody>
  </table>
 </div>${pagination(data)}`;
}

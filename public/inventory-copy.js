import {esc,toast} from './ui.js';
import {withApplicationCopy} from './login-content.js';
import {tableCopyButton} from './table-copy-button.js';

export function inventorySku(sku){
 if(!sku)return '<div class="inventory-sku">Stok kodu yok</div>';
 const button=tableCopyButton({label:'Stok kodunu kopyala',data:{'stock-copy':sku},placement:'inventory-copy'});
 return `<div class="inventory-sku"><span>${esc(sku)}</span>${button}</div>`;
}
export async function copyInventorySku(button){
 try{
  const value=button.dataset.stockCopy;
  if(navigator.clipboard?.writeText)await navigator.clipboard.writeText(value);
  else{
   const input=document.createElement('textarea');input.value=value;input.readOnly=true;input.style.cssText='position:fixed;left:-9999px';document.body.append(input);
   try{input.select();if(!withApplicationCopy(()=>document.execCommand('copy')))throw new Error('Pano kullanılamıyor');}
   finally{input.remove();button.focus({preventScroll:true});}
  }
  toast('Stok kodu kopyalandı.');
 }
 catch{toast('Kod kopyalanamadı. Tarayıcının pano iznini kontrol edin.');}
}

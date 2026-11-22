import {api,upload} from './api.js';
import {esc,icon,openDialog} from './ui.js';

export function openInventoryImage(item,{admin,endpoint,current,refresh}){
 const editable=admin&&!item.removed;
 const dialog=openDialog('Ürün görseli',`<form data-inventory-image-form class="inventory-image-form"><p><strong>${esc(item.title)}</strong></p><div class="inventory-image-preview">${item.imageUrl?`<img src="${esc(item.imageUrl)}" alt="${esc(item.title)}">`:`<span>${icon('inventory')}<small>Henüz görsel eklenmedi</small></span>`}</div>${editable?'<label>Görsel seç<input type="file" name="image" accept="image/png,image/jpeg,image/webp"></label><p class="muted">PNG, JPEG veya WebP · En fazla 5 MB ve 4096 × 4096 piksel (16 megapiksel). Görsel oranı korunur; diğer müşterilerin ürünleri değişmez.</p><div class="inventory-image-actions"><button type="submit" class="btn" disabled>Görseli kaydet</button>'+(item.imageUrl?'<button type="button" class="btn secondary" data-image-remove>Görseli kaldır</button>':'')+'</div>':''}<p class="form-error" role="alert"></p></form>`,{className:'inventory-dialog inventory-image-dialog'});
 const form=dialog.querySelector('[data-inventory-image-form]'),preview=form.querySelector('.inventory-image-preview'),error=form.querySelector('.form-error');
 let previewUrl=null,busy=false;
 const release=()=>{if(previewUrl){URL.revokeObjectURL(previewUrl);previewUrl=null;}};
 dialog.addEventListener('close',release,{once:true});
 if(!editable)return;
 const input=form.elements.image,save=form.querySelector('[type=submit]');
 input.addEventListener('change',()=>{
  release();error.textContent='';const file=input.files[0];save.disabled=true;
  if(!file)return;
  if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>5*1024*1024){error.textContent='En fazla 5 MB boyutunda PNG, JPEG veya WebP seçin.';input.value='';return;}
  previewUrl=URL.createObjectURL(file);preview.innerHTML=`<img src="${esc(previewUrl)}" alt="Seçilen ürün görselinin önizlemesi">`;save.disabled=false;
 });
 async function change(remove){
  if(busy||!current()||!dialog.open)return;
  const file=input.files[0];if(!remove&&!file)return;
  busy=true;error.textContent='';form.querySelectorAll('input,button').forEach(x=>x.disabled=true);
  try{
   if(remove)await api(endpoint('/image'),'DELETE',{productKey:item.key,version:item.version});
   else{const data=new FormData();data.append('image',file);data.append('productKey',item.key);data.append('version',String(item.version));await upload(endpoint('/image'),data);}
   if(current()){form.reset();dialog.close();await refresh();}
  }catch(e){if(dialog.open)error.textContent=e.message;}
  finally{busy=false;if(dialog.open){form.querySelectorAll('input,button').forEach(x=>x.disabled=false);save.disabled=!input.files.length;}}
 }
 form.onsubmit=event=>{event.preventDefault();void change(false);};
 form.querySelector('[data-image-remove]')?.addEventListener('click',()=>void change(true));
}

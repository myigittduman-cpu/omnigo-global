import {api} from './api.js';
import {openDialog,esc} from './ui.js';

export async function openPriceGroup(item,company,current){
 const dialog=openDialog('Pazaryeri satış fiyatını güncelle','<form class="form-grid"><strong>'+esc(item.title||item.sku)+'</strong><label>Yeni satış fiyatı (₺)<input name="price" inputmode="decimal" placeholder="21.000,00" required></label><p role="status" aria-live="polite"></p><button class="btn primary" disabled>Aktif ilanlara gönder</button></form>',{className:'inventory-dialog'});
 const form=dialog.querySelector('form'),status=form.querySelector('[role=status]'),button=form.querySelector('button');
 const suffix='?company='+encodeURIComponent(company),path='/inventory/active-marketplace-stock'+suffix;
 const alive=()=>dialog.open&&current(),id=crypto.randomUUID();
 let timer;
 dialog.addEventListener('close',()=>clearTimeout(timer),{once:true});
 const states={queued:'Sırada',checking:'Pazaryerinden doğrulanıyor',succeeded:'Fiyat doğrulandı',uncertain:'Kontrol gerekiyor',failed:'Başarısız'};
 function show(rows){
  status.textContent=rows.map(r=>r.provider+' · '+(states[r.state]||r.state)+(r.message?' · '+r.message:'')).join(' / ');
  if(rows.some(r=>['queued','checking'].includes(r.state)))timer=setTimeout(poll,5000);
 }
 async function poll(){if(!alive())return;try{const r=await api(path+'&priceRequest='+id);if(alive())show(r.results);}catch(e){if(alive())status.textContent=e.message;}}
 try{const r=await api('/inventory/active-marketplace-listings'+suffix+'&productKey='+encodeURIComponent(item.writeTarget.productKey));
  if(!alive())return;status.textContent=r.targets.map(t=>t.provider+' · '+t.sku).join(' / ')||'Uygun aktif ilan yok.';button.disabled=!r.targets.length;
  if(r.latestPrice){const history=document.createElement('p');history.textContent='Son fiyat gönderimi · '+(Number(r.latestPrice.price)/100).toLocaleString('tr-TR',{style:'currency',currency:'TRY'})+' · '+r.latestPrice.results.map(x=>x.provider+': '+(states[x.state]||x.state)).join(' / ');form.prepend(history);}
 }catch(e){if(alive())status.textContent=e.message;return;}
 form.onsubmit=async e=>{e.preventDefault();if(!alive()||button.disabled)return;
  const raw=form.elements.price.value.trim();
  if(!/^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?$/.test(raw)){status.textContent='Fiyatı 21.000,00 biçiminde girin.';return;}
  const [whole,frac='']=raw.replaceAll('.','').split(',');const cents=Number(whole)*100+Number(frac.padEnd(2,'0'));
  if(!Number.isSafeInteger(cents)||cents<1||cents>999999999999){status.textContent='Geçerli bir satış fiyatı girin.';return;}
  button.disabled=true;form.elements.price.disabled=true;
  try{const r=await api(path,'POST',{action:'price',confirmed:true,requestId:id,productKey:item.writeTarget.productKey,priceCents:cents},{confirmed:true});if(alive())show(r.results);}
  catch(err){if(alive()){status.textContent=err.message;button.disabled=false;}}
 };
}

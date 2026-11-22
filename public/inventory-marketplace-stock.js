import {api} from './api.js';
import {openDialog,esc} from './ui.js';
import {showFeedback} from './save-feedback.js';

export const marketplaceStockStates={queued:'Gönderim sıraya alındı.',sending:'Pazaryerine iletiliyor; sonuç doğrulanıyor.',accepted:'Pazaryeri isteği aldı; güncel stok adedi doğrulanıyor.',succeeded:'Pazaryeri stoğu güncellendi.',failed:'Stok gönderimi tamamlanamadı.',uncertain:'Sonuç kesinleşmedi. Tekrar göndermeden önce pazaryerini kontrol edin.'};
export function marketplaceStockForm(item){
 const targets=item.marketplaceStockTargets||[],first=targets[0];
 return `<form class="form-grid" data-marketplace-stock-form><div class="marketplace-stock-identity"><strong>${esc(item.title)}</strong><small>Stok kodu: ${esc(item.sku||'—')}</small></div><label>Pazaryeri<select name="listingKey">${targets.map(target=>`<option value="${esc(target.key)}">${esc(target.provider)} · ${esc(target.sku)}</option>`).join('')}</select></label><div class="marketplace-stock-summary"><span>Son alınan pazaryeri stoğu<strong data-source-stock>${esc(first?.quantity??'—')} adet</strong></span><span>Omnigo’daki mevcut stok<strong>${esc(item.stockQuantity??item.quantity??'—')} adet</strong></span></div><label>Gönderilecek pazaryeri stok adedi<input name="quantity" value="${esc(first?.quantity??'')}" type="number" inputmode="numeric" min="0" max="100000000" step="1" required autocomplete="off"></label><p class="form-help">Yalnız seçilen pazaryerinin stok adedi değişir. Mevcut stok modeli, Omnigo’daki manuel fark ve mağaza satışları korunur. Sıfır stok satışa kapanmaya yol açabilir.</p><label class="marketplace-stock-confirm"><input type="checkbox" name="confirmed" required><span>Bu ürünün seçilen pazaryerindeki stok adedini değiştirmeyi onaylıyorum.</span></label><p class="marketplace-stock-status" role="status" aria-live="polite">Gönderimden sonra sonuç pazaryerinden doğrulanır.</p><p class="form-error" role="alert"></p><button type="submit" class="btn primary">Pazaryerine gönder</button></form>`;
}
export function openMarketplaceStock(item,{endpoint,current,refresh}){
 if(!current()||!item.marketplaceStockTargets?.length)return;
 const dialog=openDialog('Pazaryeri stoğunu güncelle',marketplaceStockForm(item),{className:'inventory-dialog inventory-marketplace-dialog'}),form=dialog.querySelector('form'),requestId=crypto.randomUUID();
 const status=form.querySelector('[role=status]'),error=form.querySelector('[role=alert]');let busy=false;
 const alive=()=>dialog.open&&dialog.contains(form)&&current();
 const target=()=>item.marketplaceStockTargets.find(entry=>entry.key===form.elements.listingKey.value);
 const lock=value=>{form.querySelectorAll('input,select,[type=submit]').forEach(field=>field.disabled=value);};
 function select(){const entry=target();if(!entry)return;form.querySelector('[data-source-stock]').textContent=entry.quantity+' adet';form.elements.quantity.value=entry.quantity;form.elements.confirmed.checked=false;
  busy=Boolean(entry.requestId);form.querySelector('[type=submit]').disabled=busy;
  status.textContent=busy?'Bu ürün için güncelleme bekleniyor. Sonucu ürünün Hareketler bölümünde izleyebilirsiniz.':'Talep oluşturulduğunda pencere kapanır. Sonuç ürünün Hareketler bölümünde görünür.';
 }
 form.elements.listingKey.onchange=select;
 form.onsubmit=async event=>{
  event.preventDefault();if(busy||!alive())return false;const entry=target(),quantity=Number(form.elements.quantity.value);
  if(!entry||!Number.isInteger(quantity)||quantity<0||quantity>100000000||!form.elements.confirmed.checked)return false;
  busy=true;error.textContent='';lock(true);
  try{await api(endpoint('/marketplace-stock'),'POST',{requestId,productKey:item.key,listingKey:entry.key,sourceQuantity:entry.quantity,quantity,confirmed:true});if(!alive())return true;form.reset();dialog.close();showFeedback('Talep oluşturuldu',{kind:'success',force:true});void refresh({quiet:true});return true;}
  catch(failure){if(alive()){error.textContent=failure.message;lock(false);}busy=false;return false;}
 };
 select();
}

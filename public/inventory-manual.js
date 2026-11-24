import {api} from './api.js';
import {esc,openDialog} from './ui.js';
import {showFeedback} from './save-feedback.js';
import {t} from './i18n.js';

const providerFields=(providers,links=[])=>providers.map((provider,index)=>{
 const link=links.find(x=>x.provider===provider),active=link?.state==='active';
 return `<div class="inventory-provider"><label class="inventory-check"><input type="checkbox" name="market${index}" value="${esc(provider)}" ${active?'checked disabled':''}><span>${esc(provider)}</span></label>${active?'<small class="inventory-live">Canlı aktarım açık</small>':`<label class="inventory-code">Pazaryerindeki stok kodu <small>(farklıysa)</small><input name="code${index}" maxlength="180" value="${esc(link?.code||'')}" placeholder="Üründeki stok kodu kullanılacak"></label>`}${link?.reason?`<small class="inventory-history-note">${esc(link.reason)}</small>`:''}</div>`;
}).join('');
const selection=(form,providers)=>providers.flatMap((provider,index)=>form.elements['market'+index]?.checked&&!form.elements['market'+index].disabled?[{provider,code:form.elements['code'+index]?.value||''}]:[]);
const note='<p class="muted">Yalnız seçtiğiniz pazaryerleri, aynı stok kodu ile doğrulanır. Canlı stok başarılı eşleşmeden sonra pazaryerinden okunur. Siparişler mağaza stoğundan düşülmez. Pazaryerlerine stok yazılmaz.</p>';

export function openManualProduct({endpoint,current,refresh,providers=[],online=true,horoz=false}){
 const dialog=openDialog('Manuel ürün ekle',`<form class="form-grid" data-manual-product>
 <div class="inventory-form-pair"><label>Ürün adı<input name="title" maxlength="500" required></label><label>Stok kodu<input name="sku" maxlength="180" required></label><label>Barkod <small>(isteğe bağlı)</small><input name="barcode" maxlength="32" inputmode="numeric"></label><label>Marka <small>(isteğe bağlı)</small><input name="brand" maxlength="180"></label><label>Model <small>(isteğe bağlı)</small><input name="model" maxlength="180"></label><label>Başlangıç ${horoz?'ortak':'mağaza'} stoğu<input name="quantity" type="number" step="1" min="-100000000" max="100000000" required></label></div>
 <label>Pazaryerleri ile bağlantı kurulsun mu?<select name="tracking" required><option value="">Takip şeklini seçin</option value="manual">Hayır, tamamen manuel takip</option>${online?'<option value="live">Evet, pazaryerleri ile eşleştir</option>':''}</select></label>
 <p data-manual-note class="muted" hidden>Canlı bağlantı yok. Stok yalnız elle değişir. Daha sonra pazaryerlerini seçip canlı aktarımı başlatabilirsiniz.</p>
 <fieldset data-market-selection hidden disabled><legend>Bağlanacak pazaryerleri</legend>${providerFields(providers)}</fieldset>${note}
 <p class="form-error" role="alert"></p><button type="submit">Kaydet</button></form>`,{className:'inventory-dialog'});
 const form=dialog.querySelector('form'),requestId=crypto.randomUUID();let busy=false;
 form.elements.tracking.onchange=()=>{const live=form.elements.tracking.value==='live',group=form.querySelector('fieldset');group.hidden=!live;group.disabled=!live;form.querySelector('[data-manual-note]').hidden=form.elements.tracking.value!=='manual';};
 form.onsubmit=async event=>{event.preventDefault();if(busy||!current())return false;const error=form.querySelector('[role=alert]');error.textContent='';if(!form.reportValidity())return false;
  const connect=form.elements.tracking.value==='live',selected=connect?selection(form,providers):[];
  if(connect&&!selected.length){error.textContent='En az bir pazaryeri seçin.';return false;}
  const input=Object.fromEntries(['title','sku','barcode','brand','model'].map(name=>[name,form.elements[name].value]));busy=true;form.querySelector('button').disabled=true;
  try{await api(endpoint('/products'),'POST',{...input,requestId,quantity:Number(form.elements.quantity.value),connect,providers:selected});form.reset();dialog.close();await refresh();}
  catch(e){error.textContent=e.message;return false;}finally{busy=false;form.querySelector('button').disabled=false;}
 };
}

export function openProductMatching(item,{endpoint,current,refresh,providers=[]}){
 const dialog=openDialog('Pazaryerleri ile eşleştir',`<form class="form-grid" data-product-matching><p><strong>${esc(item.title)}</strong></p><p class="muted">Stok kodu: ${esc(item.sku)}${item.barcode?' · Barkod: '+esc(item.barcode):''}</p><fieldset><legend>Pazaryerlerini seçin</legend>${providerFields(providers,item.links)}</fieldset>${note}<p class="muted">Eşleşmeyen pazaryerinin nedeni ürünün yanında gösterilir. Diğer aktif bağlantılar devam eder; yeniden denemek başlangıç zamanlarını değiştirmez.</p><p class="form-error" role="alert"></p><button type="submit">Canlı aktarımı başlat</button></form>`,{className:'inventory-dialog'});
 const form=dialog.querySelector('form');let busy=false;
 form.onsubmit=async event=>{event.preventDefault();if(busy||!current())return false;const error=form.querySelector('[role=alert]');error.textContent='';const selected=selection(form,providers);if(!selected.length){error.textContent='Eşleştirilecek en az bir pazaryeri seçin.';return false;}busy=true;form.querySelector('button').disabled=true;
  try{
   const result=await api(endpoint('/matching'),'POST',{productKey:item.key,providers:selected});
   if(!current()||!dialog.open)return;
   if(result.queued!==true)throw new Error(t('Pazaryeri eşleştirmesi sıraya alınamadı.'));
   form.reset();dialog.close();
   showFeedback(t('Pazaryeri eşleştirmesi sıraya alındı.'),{kind:'pending'});
   await refresh();
  }
  catch(e){error.textContent=e.message;return false;}finally{busy=false;form.querySelector('button').disabled=false;}
 };
}

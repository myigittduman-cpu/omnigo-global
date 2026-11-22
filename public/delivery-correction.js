import {turkeyCities} from './turkey-cities.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {esc,openDialog} from './ui.js';

const cities=turkeyCities;
export function missingValue(value,label){return value?esc(value):i18nHtml`<span class="delivery-missing">${esc(label)} eksik</span>`;}
export function correctionNote(order,editable=true){
 const issues=order.issues||[],outside=order.city==='Diğer / Kontrol',rawCity=order.manualCorrections?.city||order.details?.province||'';
 const notes=issues.filter(text=>text!=='Teslimat ili kontrol edilmeli');
 if(outside)notes.unshift(rawCity&&rawCity!=='Diğer / Kontrol'?i18nText`Kapsam dışı sipariş · Teslimat ili: ${rawCity}`:i18nT('Teslimat ili eksik veya doğrulanamadı'));
 if(!notes.length&&!order.manualCorrections)return '';
 return i18nHtml`<footer class="delivery-review"><div>${notes.map(text=>i18nHtml`<p class="delivery-missing">${esc(text)}</p>`).join('')}${order.manualCorrections?i18nHtml('<small>Yönetici tarafından tamamlanan bilgiler korunuyor.</small>'):''}</div>${editable&&order.active&&order.queue!=='cancelled'&&order.queue!=='delivered'?i18nHtml`<button type="button" class="btn" data-correct-order="${order.id}">${notes.length?'Bilgileri tamamla':i18nT('Düzeltilen bilgiler')}</button>`:''}</footer>`;
}
export function correctionForm(data){
 return i18nHtml`<p class="delivery-note">Eksik alanlar kırmızı gösterilir. Kapsam dışındaki il doğruysa değiştirmeyin; sipariş kontrol listesinde kalır. Değişiklik yalnız Omnigo kayıtlarına uygulanır.</p><form class="delivery-correction-form"><div class="delivery-correction-grid">${data.fields.map(field=>i18nHtml`<label class="${field.error?'has-error':''}"><span>${esc(field.label)}</span>${field.key==='address'?i18nHtml`<textarea name="${esc(field.key)}" maxlength="${field.limit}" rows="3">${esc(field.value)}</textarea>`:i18nHtml`<input name="${esc(field.key)}" value="${esc(field.value)}" maxlength="${field.limit}" ${field.key==='city'?'list="delivery-correction-cities"':''} ${field.key==='phone'?'inputmode="tel"':''} ${field.key.startsWith('productQuantity:')?'inputmode="numeric"':''}>`}<small data-field-message>${esc(field.message)}</small></label>`).join('')}</div><datalist id="delivery-correction-cities">${cities.map(c=>i18nHtml`<option value="${c}"></option>`).join('')}</datalist><p class="form-error" role="alert"></p><button type="submit" class="btn primary">Kaydet ve otomatik yerleştir</button></form>`;
}
export async function openDeliveryCorrection({id,endpoint,current,onSaved,onClose}){
 let dialog;
 try{
  const data=await api(endpoint(`orders/${id}/correction`));if(!current()){onClose();return;}
  dialog=openDialog(i18nText`Sipariş bilgilerini tamamla · ${data.orderNumber||data.id}`,correctionForm(data));dialog.classList.add('delivery-correction-dialog');
  dialog.addEventListener('close',onClose,{once:true});const form=dialog.querySelector('form'),submit=form.querySelector('[type=submit]');let saving=false;
  form.addEventListener('input',event=>{const label=event.target.closest('label');if(label){label.classList.remove('has-error');label.querySelector('[data-field-message]').textContent=i18nT('Kaydedildiğinde doğrulanacak.');}});
  dialog.addEventListener('cancel',event=>{if(saving)event.preventDefault();});
  form.onsubmit=async event=>{
   event.preventDefault();if(saving||!current())return;saving=true;submit.disabled=true;form.querySelector('.form-error').textContent='';
   try{const result=await api(endpoint(`orders/${id}/correction`),'PATCH',{version:data.version,fields:Object.fromEntries(new FormData(form))});if(!current())return;dialog.close();await onSaved(result);}
   catch(error){form.querySelector('.form-error').textContent=error.message;}
   finally{saving=false;submit.disabled=false;}
  };
 }catch(error){if(!dialog)onClose();throw error;}
}

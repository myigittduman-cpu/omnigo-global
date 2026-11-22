import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {esc} from './ui.js';

export function customerStoresForm(items){
 return i18nHtml`<p class="form-help">Mağaza adları İş Ortağım’da da güncellenir. Her mağazayı kendi Kaydet düğmesiyle kaydedin.</p><div class="customer-store-list">${items.map(item=>i18nHtml`<form data-customer-store="${Number(item.id)}" class="form-grid customer-store-form"><label>${esc(item.name)}<input name="storeName" value="${esc(item.storeName)}" placeholder="Pazaryerindeki mağaza adı" maxlength="150" required></label><button class="btn" type="submit">Kaydet</button><p class="form-error" role="alert"></p><p class="form-help" role="status"></p></form>`).join('')||i18nHtml('<p>Bu müşteri için tanımlı mağaza yok.</p>')}</div>`;
}
export function bindCustomerStores(root,{items,company,current,onSave}){
 for(const form of root.querySelectorAll('[data-customer-store]')){
  const item=items.find(item=>Number(item.id)===Number(form.dataset.customerStore));let busy=false;
  form.onsubmit=async event=>{
   event.preventDefault();if(busy||!current())return;busy=true;
   const button=form.querySelector('button'),error=form.querySelector('[role=alert]'),status=form.querySelector('[role=status]');
   button.disabled=true;error.textContent='';status.textContent=i18nT('Kaydediliyor…');
   try{
    const storeName=form.elements.storeName.value.trim();
    await api(`/management/stores/${item.id}?company=${company}`,'PUT',{storeName,version:item.version});
    item.storeName=storeName;item.version++;
    if(!current())return;status.textContent=i18nT('Mağaza adı kaydedildi.');await onSave();
   }catch(e){if(current()){status.textContent='';error.textContent=e.message;}}
   finally{busy=false;button.disabled=false;}
  };
 }
}

import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';

/** The source form stays mounted; a failed save can never approve navigation. */
export function confirmPendingChanges({changes,source='',target='',save,discard,current=()=>true}){
 return new Promise(resolve=>{
  const dialog=document.createElement('dialog');dialog.className='pending-changes-dialog';dialog.setAttribute('aria-label',i18nT('Kaydedilmemiş işlemler'));
  dialog.innerHTML=i18nHtml`<h2>Değişiklikleri kaydetmek ister misiniz?</h2><p>${source?i18nText`${esc(source)} müşterisinde kaydedilmemiş işlemler var.`:i18nT('Kaydedilmemiş işlemler var.')}${target?i18nText` ${esc(target)} müşterisine geçmeden önce nasıl devam edilsin?`:''}</p><ul>${changes.map(label=>i18nHtml`<li>${esc(label)}</li>`).join('')}</ul><p data-pending-error class="form-error" role="alert"></p><div class="pending-actions"><button type="button" class="btn primary" data-pending-save>${target?i18nT('Kaydet ve müşteriyi değiştir'):i18nT('Kaydet ve devam et')}</button><button type="button" class="btn" data-pending-discard>${target?i18nT('Kaydetmeden değiştir'):'Kaydetmeden devam et'}</button><button type="button" class="btn" data-pending-cancel>Vazgeç</button></div>`;
  let busy=false,accepted=false;const buttons=[...dialog.querySelectorAll('button')];
  const run=async action=>{
   if(busy)return;busy=true;buttons.forEach(button=>button.disabled=true);
   try{if(!current())throw new Error(i18nT('Ekran değişti. İşlemi yeniden açın.'));const result=await action();if(result===false)throw new Error(i18nT('Kayıt tamamlanamadı. Değişiklikleriniz bu müşteride korunuyor.'));accepted=true;dialog.close();}
   catch(error){dialog.querySelector('[data-pending-error]').textContent=error.message;}
   finally{busy=false;buttons.forEach(button=>button.disabled=false);}
  };
  dialog.querySelector('[data-pending-save]').onclick=()=>run(save);
  dialog.querySelector('[data-pending-discard]').onclick=()=>run(discard);
  dialog.querySelector('[data-pending-cancel]').onclick=()=>dialog.close();
  dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
  dialog.addEventListener('close',()=>{dialog.remove();resolve(accepted);},{once:true});
  document.body.append(dialog);dialog.showModal();dialog.querySelector('[data-pending-cancel]').focus();
 });
}

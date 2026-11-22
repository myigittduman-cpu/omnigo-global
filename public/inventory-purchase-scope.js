import {html,t} from './i18n.js';
import {esc} from './ui.js';

/** Closing the choice is distinct from saving a price for future orders. */
export function choosePurchasePriceScope(item,current){
 if(!current())return Promise.resolve(null);
 const dialog=document.createElement('dialog');
 dialog.className='action-confirm-dialog inventory-price-scope';
 dialog.setAttribute('aria-label',t('Geçmiş siparişler için uygulansın mı?'));
 const description=[item.sku,item.title].filter(Boolean).join(' · ');
 dialog.innerHTML=html`<h2>Geçmiş siparişler için uygulansın mı?</h2>
  ${description?html`<p class="form-help">${esc(description)}</p>`:''}
  <div class="inventory-price-scope-choices">
   <button type="button" class="btn" data-price-scope="past">Evet, geçmiş siparişlere de uygula</button>
   <button type="button" class="btn" data-price-scope="future">Hayır, şimdi ve sonrasına uygula</button>
   <button type="button" class="btn" data-price-scope="cancel" autofocus>Vazgeç</button>
  </div>`;
 return new Promise(resolve=>{
  const observer=new MutationObserver(()=>{if(dialog.open&&!current())dialog.close();});
  dialog.addEventListener('click',event=>{
   const option=event.target.closest('[data-price-scope]');
   if(option&&dialog.open)dialog.close(current()?option.dataset.priceScope:'');
  });
  dialog.addEventListener('keydown',event=>{
   if(event.key==='Enter'&&(event.repeat||event.isComposing))event.preventDefault();
  });
  dialog.addEventListener('close',()=>{
   observer.disconnect();
   const choice=dialog.returnValue;
   dialog.remove();
   resolve(current()&&['past','future'].includes(choice)?choice==='past':null);
  },{once:true});
  document.body.append(dialog);
  try{
   dialog.showModal();
   observer.observe(document.body,{childList:true,subtree:true});
  }catch(error){dialog.remove();throw error;}
 });
}

import {api} from './api.js';
import {customerCan} from './customer-access.js';
import {t} from './i18n.js';

export function bindCityProductOutputs(root,{state,company,current,model}){
 const actions=root.querySelector('[data-products-outputs]');
 const message=root.querySelector('[data-products-output-status]');
 const panel=root.querySelector('#city-products-panel');
 const permitted=()=>customerCan(state,'cityProducts','export');
 let generation=0,pending=null;
 function sync(){
  actions.hidden=!permitted();
  message.hidden=actions.hidden;
  const unavailable=actions.hidden||Boolean(pending)||panel.hasAttribute('aria-busy')||!model.data?.total;
  for(const button of actions.querySelectorAll('button'))button.disabled=unavailable;
 }
 function report(text){
  message.textContent=text;
  message.scrollTop=0;
  if(text)message.tabIndex=0;else message.removeAttribute('tabindex');
 }
 function reset(){
  generation++;
  pending?.abort();
  pending=null;
  report('');
  sync();
 }
 actions.addEventListener('click',async event=>{
  const button=event.target.closest('[data-products-output]');
  if(!button||button.disabled||!current()||!permitted()||pending||!model.data?.total)return;
  const controller=new AbortController(),ticket=++generation,city=model.city,sort=model.sort??'default';
  const stillCurrent=()=>current()&&ticket===generation&&permitted()&&model.city===city&&model.data?.city===city
   &&(model.sort??'default')===sort&&(model.data?.sort??'default')===sort;
  pending=controller;sync();
  report(t('Güncel siparişler hazırlanıyor…'));
  try{
   const query=new URLSearchParams({company,city,sort,group:'order',export:'1'});
   const data=await api('/city-products?'+query,'GET',undefined,{signal:controller.signal});
   if(!stillCurrent())return;
   if(data.city!==city||(data.sort??'default')!==sort||data.hasMore!==false||!Array.isArray(data.items)||data.items.length!==data.total||
      data.items.some(order=>!Array.isArray(order.deliveries))){
    throw new Error(t('Sipariş listesinin tamamı alınamadı. Tekrar deneyin.'));
   }
   if(!data.total){report(t('Seçili illerde aktarılacak sipariş bulunmuyor.'));return;}
   const {exportCityProducts}=await import('./city-products-export.js');
   if(!stillCurrent())return;
   const result=await exportCityProducts({format:button.dataset.productsOutput,state,city,orders:data.items,stillCurrent});
   if(stillCurrent())report(result||'');
  }catch(error){
   if(stillCurrent())report(error.message||t('İşlem tamamlanamadı. Tekrar deneyin.'));
  }finally{
   if(pending===controller){pending=null;sync();}
  }
 });
 return {sync,reset};
}

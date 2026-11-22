import {confirmMutation} from './action-confirmation.js';
import {createValueDraftGuard} from './value-draft-guard.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {reorderNavigation} from './navigation-reorder.js';
import {esc,icon,openDialog,toast} from './ui.js';
import {customerPageNames} from './customer-modules.js';
import {customerOverviewKeys} from './customer-overview-menu.js';
const key=user=>`omnigo:admin-menu:${user.id}:v1`;
export function orderedNavigation(names,user,storage=globalThis.localStorage){
 const standard=['overview',...customerOverviewKeys,'channels','customers','orders','inventory','marketplaceStocks','shipments','inbox','deliveries','cityProducts','analytics','payments','shipmentInvoices','tasks','reports','support','connections','financeProfitability','returns'];
 const ids=[...standard.filter(id=>Object.hasOwn(names,id)),...Object.keys(names).filter(id=>!standard.includes(id))];
 return Object.fromEntries(ids.map(id=>[id,names[id]]));
}
export function editNavigation(names,state,onSave){
 if(state.user?.role!=='admin')return;
 names=customerPageNames(names,state);
 let dragging=null;
 const user=state.user;let ids=Object.keys(orderedNavigation(names,user));
 const guard=createValueDraftGuard({label:i18nT('Menü sırası'),read:()=>ids,restore:value=>{ids=value;},current:()=>state.user===user,write:async value=>{
  await confirmMutation('/account/navigation','PUT',{});if(state.user!==user)return false;
  localStorage.setItem(key(user),JSON.stringify(value));return true;
 }});
 const dialog=openDialog(i18nT('Menü sırasını düzenle'),'',{className:'navigation-order-dialog',draftGuard:guard}),body=dialog.querySelector('.dialog-body');
 dialog.addEventListener('close',()=>{if(state.user===user)onSave();},{once:true});
 function paint(focus){
  body.innerHTML=i18nHtml`<p>Sekmeleri fareyle sürükleyerek sıralayın. Telefonda veya klavyeyle ok düğmelerini kullanabilirsiniz. Sıralama bu cihazda yönetici hesabınız için saklanır.</p><ol class="navigation-order-list">${ids.map((id,index)=>i18nHtml`<li draggable="true" data-order-id="${id}"><span class="navigation-order-label"><span class="navigation-order-icon">${icon(id)}</span>${esc(names[id])}</span><div><button type="button" class="btn" data-move="-1" data-id="${id}" aria-label="${esc(names[id])} yukarı taşı" ${index===0?'disabled':''}>↑</button><button type="button" class="btn" data-move="1" data-id="${id}" aria-label="${esc(names[id])} aşağı taşı" ${index===ids.length-1?'disabled':''}>↓</button></div></li>`).join('')}</ol><p role="alert" data-navigation-error></p><div class="navigation-order-actions"><button class="btn" data-default>Varsayılan sıra</button><button class="btn primary" data-save>Kaydet</button></div>`;
  if(focus)body.querySelector(`[data-id="${focus.id}"][data-move="${focus.move}"]:not(:disabled)`)?.focus();
 }
 body.onclick=async event=>{
  if(guard.isBusy())return;
  const button=event.target.closest('button');if(!button)return;
  if(button.dataset.move){const index=ids.indexOf(button.dataset.id),next=index+Number(button.dataset.move);if(index<0||next<0||next>=ids.length)return;[ids[index],ids[next]]=[ids[next],ids[index]];paint({id:button.dataset.id,move:button.dataset.move});}
  else if(button.hasAttribute('data-default')){ids=Object.keys(names);paint();}
  else if(button.hasAttribute('data-save')){if(state.user!==user)return;try{if(await guard.save()===false)return;}catch(error){body.querySelector('[data-navigation-error]').textContent=error.cancelled?'':i18nT('Sıralama kaydedilemedi. Tarayıcı depolama iznini kontrol edin.');return;}dialog.close();toast(i18nT('Menü sıralaması kaydedildi.'));}
 };
 body.addEventListener('dragstart',event=>{
  const row=event.target.closest('[data-order-id]');
  if(!row||guard.isBusy()){event.preventDefault();return;}
  dragging=row.dataset.orderId;event.dataTransfer.effectAllowed='move';
  event.dataTransfer.setData('text/plain',dragging);row.classList.add('is-dragging');
 });
 body.addEventListener('dragover',event=>{
  const row=event.target.closest('[data-order-id]');if(!dragging||!row||guard.isBusy())return;
  event.preventDefault();event.dataTransfer.dropEffect='move';
  body.querySelectorAll('.is-drop-target').forEach(node=>node.classList.remove('is-drop-target'));
  if(row.dataset.orderId!==dragging)row.classList.add('is-drop-target');
 });
 body.addEventListener('drop',event=>{
  const row=event.target.closest('[data-order-id]');if(!dragging||!row||guard.isBusy())return;
  event.preventDefault();ids=reorderNavigation(ids,dragging,row.dataset.orderId);dragging=null;paint();
 });
 body.addEventListener('dragend',()=>{dragging=null;body.querySelectorAll('.is-dragging,.is-drop-target').forEach(node=>node.classList.remove('is-dragging','is-drop-target'));});
 paint();
}

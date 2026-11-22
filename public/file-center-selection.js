import {html,text} from './i18n.js';
import {esc} from './ui.js';

export const selectionHeader=()=>html`<th scope="col" class="file-center-select"><input type="checkbox"
 data-file-center-all aria-label="Bu sayfadaki tüm kayıtları seç"></th>`;
export const selectionCell=(key,label)=>html`<td class="file-center-select"><input type="checkbox"
 data-file-center-select="${esc(key)}" aria-label="${esc(text`${label} kaydını seç`)}"></td>`;

export function bindFileCenterSelection(root,current){
 const keys=new Set();
 const all=()=>root.querySelector('[data-file-center-all]');
 const boxes=()=>[...root.querySelectorAll('[data-file-center-select]')];
 const choose=box=>box.checked?keys.add(box.dataset.fileCenterSelect):keys.delete(box.dataset.fileCenterSelect);
 const count=root.querySelector('[data-file-center-selection-count]');

 function update({ready,onChange}){
  const controls=boxes();
  for(const checkbox of controls){
   checkbox.disabled=!ready;
   checkbox.checked=keys.has(checkbox.dataset.fileCenterSelect);
  }
  const selected=controls.filter(checkbox=>checkbox.checked);
  count.textContent=selected.length?text`${selected.length} kayıt seçildi`:'';
  const selectAll=all();
  if(selectAll){
   selectAll.checked=controls.length>0&&controls.length===selected.length;
   selectAll.indeterminate=selected.length>0&&!selectAll.checked;
   selectAll.disabled=!ready||!controls.length;
  }
  onChange(selected.length);
 }

 function bind(state){
  root.addEventListener('change',event=>{
   const input=event.target;
   if(!current()||input.disabled)return;
   if(input.matches('[data-file-center-all]')){
    keys.clear();
    if(input.checked)for(const box of boxes())keys.add(box.dataset.fileCenterSelect);
   }else if(input.matches('[data-file-center-select]'))choose(input);
   else return;
   update(state());
  });
 }
 return {bind,update,clear:()=>keys.clear(),selected:items=>items.filter(item=>keys.has(String(item.key??item.id)))};
}

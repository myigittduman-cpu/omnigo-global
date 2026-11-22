import {html,t} from './i18n.js';
import {esc,openDialog} from './ui.js';
import {closeDialogThen} from './dialog-return.js';
import {canLeaveDialogDrafts} from './dialog-drafts.js';
import {requestCustomerSwitch} from './customer-switch.js';

export function permissionStoreSelector(companies,selected=''){
 return html`<label class="permission-store"><span>İşlem yapmak istediğiniz mağaza</span><select data-permission-store><option value="">Mağaza seçin</option>${companies.map(company=>html`<option value="${Number(company.id)}" ${Number(company.id)===Number(selected)?'selected':''}>${esc(company.name)}</option>`).join('')}</select><small>Değişiklikler yalnız seçtiğiniz mağazaya, Kaydet ve onaydan sonra uygulanır.</small></label>`;
}

export function choosePermissionStore(state,onSelect){
 const dialog=openDialog(t('Kullanıcı Yetkileri'),permissionStoreSelector(state.companies),{className:'permission-store-dialog'});
 const select=dialog.querySelector('[data-permission-store]');
 select.onchange=async()=>{const companyId=Number(select.value);if(!state.companies.some(company=>Number(company.id)===companyId))return;select.disabled=true;try{const transition=requestCustomerSwitch(companyId);if(transition!==null){await transition;return;}await closeDialogThen(dialog,()=>onSelect(companyId));}finally{if(select.isConnected)select.disabled=false;}};
}

export function bindPermissionStore(dialog,state,companyId,{blocked,onSelect}){
 const select=dialog.querySelector('[data-permission-store]');
 select.onchange=async()=>{
  const next=Number(select.value);
  select.value=String(companyId);
  if(blocked()||!next||next===Number(companyId)||!state.companies.some(company=>Number(company.id)===next))return;
  select.disabled=true;
  try{const transition=requestCustomerSwitch(next);if(transition!==null){await transition;return;}if(await canLeaveDialogDrafts())await onSelect(next);}
  finally{if(select.isConnected)select.disabled=false;}
 };
}

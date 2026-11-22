import {html} from './i18n.js';
import {esc} from './ui.js';
import {customerAccountRoleLabel,customerAccountRoleOptions} from './customer-account-roles.js';

export const permissionAccountRole=(configuration,id)=>configuration.roles?.[id]||'store_manager';
const roleLabel=(account,configuration)=>customerAccountRoleLabel({...account,storeRole:permissionAccountRole(configuration,account.id)});

export function permissionAccountList(accounts,configuration){
 if(!accounts.length)return html`<p class="form-help">Bu mağazada kullanıcı bulunmuyor.</p>`;
 return html`<div class="table-scroll permission-account-scroll" tabindex="0" aria-label="Mağaza kullanıcıları">
 <table class="corporate-table permission-account-table">
  <thead><tr><th scope="col">Kullanıcı</th><th scope="col">Rol</th><th scope="col">Durum</th><th scope="col">İşlemler</th></tr></thead>
  <tbody>${accounts.map(account=>{
   const id=Number(account.id);
   return html`<tr>
    <th scope="row"><strong>${esc(account.name)}</strong><small>${esc(account.username||account.email)}</small></th>
    <td data-permission-account-role>${esc(roleLabel(account,configuration))}</td>
    <td>${Number(account.active)?'Aktif':'Pasif'}</td>
    <td><button type="button" class="btn" data-permission-account="${id}" aria-controls="permission-account-details"
     aria-label="${esc(account.name)} · Yetkiler">Yetkiler</button></td>
   </tr>`;
  }).join('')}</tbody>
 </table></div>`;
}

export function updatePermissionAccountRole(list,account,configuration){
 const button=list.querySelector(`[data-permission-account="${Number(account.id)}"]`);
 const cell=button?.closest('tr').querySelector('[data-permission-account-role]');
 if(cell)cell.textContent=roleLabel(account,configuration);
}

export function personalPermissionSettings(account,configuration){
 const role=permissionAccountRole(configuration,account.id);
 return html`<header class="permission-personal-heading">
  <div><h3 id="permission-personal-title" tabindex="-1">Kişiye özel ayarlar</h3><p>${esc(account.name)}</p></div>
  <label><span>Mağaza rolü</span><select data-user-store-role>${customerAccountRoleOptions(role)}</select></label>
  <button type="button" class="btn" data-load-role-defaults>Varsayılana dön</button>
 </header>`;
}

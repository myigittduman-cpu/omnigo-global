import {html,t} from './i18n.js';
import {esc} from './ui.js';

export function permissionTargets(item,selected){
 const ids=selected?.users||[];
 return html`<div class="permission-target-heading"><strong>${esc(item.name)} · ${item.users.length} kullanıcı</strong><label><input type="checkbox" name="companyAll" ${item.users.length&&ids.length===item.users.length?'checked':''} ${item.users.length?'':'disabled'}>Bu müşterinin tüm kullanıcıları</label></div><div class="permission-target-list">${item.users.map(user=>html`<label><input type="checkbox" data-batch-user="${user.id}" ${ids.includes(Number(user.id))?'checked':''}><span><strong>${esc(user.name)}</strong><small>${esc(user.username||user.email||'')} · ${t(Number(user.active)?'Aktif':'Pasif')}</small></span></label>`).join('')||html('<p class="form-help">Bu müşteriye bağlı kullanıcı yok. Mağazalar → Kullanıcı Yetkileri bölümünden kullanıcı ekleyebilirsiniz.</p>')}</div>`;
}

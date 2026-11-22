import {html,t} from './i18n.js';

const labels={
 store_manager:'Mağaza Yetkilisi',
 accounting_logistics:'Muhasebe & Lojistik',
 store_admin:'Yönetici'
};

export function customerAccountRoleLabel(user){
 if(user.role==='client'&&user.storeRole==='store_admin')return labels.store_admin;
 return t(user.role==='admin'?'Omnigo':labels[user.storeRole]||labels.store_manager);
}

export function customerAccountRoleOptions(selected=''){
 return Object.entries(labels).map(([role,label])=>html`<option value="${role}" ${selected===role?'selected':''}>${role==='store_admin'?label:t(label)}</option>`).join('');
}

export function customerAccountRoleField(selected='store_manager'){
 return html`<label data-store-role>Mağaza rolü
  <select name="storeRole" required>
   ${customerAccountRoleOptions(selected)}
  </select>
  <small>Yeni hesabın başlangıç izinleri seçilen role göre uygulanır.</small>
 </label>`;
}

import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc,icon} from './ui.js';
import {companyIdentity} from './workspace-entry.js';
import {customerSelector} from './customer-selector.js';

export function customerPage(state){
 const company=state.companies.find(item=>Number(item.id)===Number(state.company));
 const actions=[['customerSetup',i18nT('Müşteri Kurulumu'),i18nT('Yeni müşteri oluşturun, kullanıcı hesabını ve pazaryerlerini sırayla kurun.'),'connections'],['customerPermissions',i18nT('Kullanıcı Yetkileri'),i18nT('Kullanıcı hesaplarını, kişiye özel izinleri ve rol varsayılanlarını yönetin.'),'store'],['manageCustomer',i18nT('Firma ayarları'),i18nT('Firma bilgilerini, logoyu, e-posta işlemlerini ve sevkiyat kapsamını yönetin.'),'store']];
 return i18nHtml`<div class="customer-action-grid">${actions.map(([id,title,description,symbol])=>i18nHtml`<button type="button" class="customer-action" id="${id}"><span class="customer-action-icon">${icon(symbol)}</span><strong>${title}</strong><span>${description}</span><small>İşlemleri aç ${icon('arrow')}</small></button>`).join('')}<button type="button" class="customer-action" id="customerMarketplaces"><span class="customer-action-icon">${icon('channels')}</span><strong>Pazaryeri ayarları</strong><span>Mağaza ekleyin; API bilgilerini, görünürlüğü ve bağlantıları seçili mağaza için yönetin.</span><small>Ayarları aç ${icon('arrow')}</small></button></div><div class="customer-page-footer"><button type="button" class="text-btn" data-workspace-switch>Mağaza seçim ekranına dön</button></div>`;
}

export function customerContext(state,company,actions=''){
 const admin=state.user.role==='admin';
 return i18nHtml`<section class="client-context ${admin?'admin-client-context':''}"><div class="context-identity"><span class="client-monogram" data-company-logo="${Number(company.id)}">${companyIdentity(company)}</span><div><span class="context-label">${admin?i18nT('SEÇİLİ MAĞAZA'):i18nT('MARKANIZIN ÇALIŞMA ALANI')}</span><strong data-company-name="${Number(company.id)}">${esc(company.name)}</strong><span class="context-sector">${state.data.channels.filter(c=>c.status==='active').length} aktif mağaza · ${state.data.channels.filter(c=>c.connection==='connected').length} otomatik bağlantı</span></div></div>${admin?customerSelector(state):i18nHtml('<div class="context-summary"><span>Hizmet veren ekip</span><strong>Omnigo Global</strong></div>')}${actions?`<div class="context-actions">${actions}</div>`:''}</section>`;
}

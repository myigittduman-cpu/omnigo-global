import {openSettingsPageModal} from './settings-page-modal.js';
import {shipmentDocumentPages} from './shipment-document-pages.js';
import {customerOverviewKeys} from './customer-overview-menu.js';
const storageKey = 'omnigo.settings.return';
const pages = new Set(['/account-security.html', '/notification-settings.html', '/privacy.html', '/account-deletion.html', '/support.html']);
const views = new Set(['overview', ...customerOverviewKeys, 'customers', 'fileCenter', 'channels', 'orders', 'inventory', 'returns', 'analytics', 'connections', 'payments', 'tasks', 'reports', 'support', 'shipments', 'deliveries', ...Object.keys(shipmentDocumentPages)]);

// The workspace stays mounted; legacy return records are still consumed below.
export function openSettingsAccountPage(href, state,options={}) {
  const url = new URL(href, location.href);
  if (url.origin !== location.origin || !pages.has(url.pathname)) return false;
  const view=openSettingsPageModal(url,state,options);
  return options.panel?view:true;
}

export function bindDesktopNotificationSettings(state){
 const take=window.__OMNIGO_TAKE_NOTIFICATION_SETTINGS__;
 if(typeof take!=='function')return;
 let waiting=false,busy=false;
 const canOpen=()=>state.user&&!state.user.mustChangePassword&&!document.querySelector('dialog[open]');
 async function open(){
  if(busy||!canOpen())return;
  busy=true;
  try{
   waiting=waiting||await take();
   if(waiting&&canOpen()){
    waiting=false;
    openSettingsAccountPage('/notification-settings.html',state);
   }
  }catch{}finally{busy=false;}
 }
 window.addEventListener('omnigo-desktop-notification-settings',open);
 window.addEventListener('omnigo-session-ready',open);
 document.addEventListener('close',open,true);
}

export function pendingSettingsReturn(user, storage = sessionStorage, now = Date.now()) {
  let record;
  try {
    record = JSON.parse(storage.getItem(storageKey) || 'null');
  } catch { return null; }
  if (!record || String(record.userId) !== String(user?.id) || !Number.isFinite(record.savedAt) || now < record.savedAt || now - record.savedAt > 6 * 60 * 60 * 1000) return null;
  if (!views.has(record.view)) return null;
  if (record.view==='fileCenter' && user?.role!=='admin') return null;
  const customerId = user?.companyId ?? user?.company_id;
  if (user?.role !== 'admin' && Number(customerId) !== Number(record.companyId)) return null;
  return record;
}

export function takeSettingsReturn(user, companies, storage = sessionStorage, now = Date.now()) {
  const record=pendingSettingsReturn(user,storage,now);
  try { storage.removeItem(storageKey); } catch { return null; }
  if(!record||!companies.some(company=>Number(company.id)===Number(record.companyId)))return null;
  return {companyId: Number(record.companyId), view: record.view, month: /^\d{4}-\d{2}$/.test(record.month) ? record.month : null};
}

import {openSite,readSeller,requestPortal} from './browser-tabs.js';

const alarm='omnigo-upcoming-payments';
const access={origins:chrome.runtime.getManifest().optional_host_permissions};
const providers=['pttavm','idefix','koctas'];
let running=null;

async function record(key,values) {
  const {statuses={}}=await chrome.storage.local.get('statuses');
  statuses[key]={...statuses[key],...values};
  await chrome.storage.local.set({statuses});
}

async function schedule() {
  const {enabled}=await chrome.storage.local.get('enabled');
  if (!enabled) return;
  if (!await chrome.permissions.contains(access)) {
    await record('admin',{message:'Aktarım için tarayıcıda izin verin.'});
    return;
  }
  if (!await chrome.alarms.get(alarm)) await chrome.alarms.create(alarm,{delayInMinutes:1,periodInMinutes:15});
}

async function cycle() {
  const {enabled}=await chrome.storage.local.get('enabled');
  if (!enabled) return;
  if (!await chrome.permissions.contains(access)) {
    await record('admin',{message:'Aktarım için tarayıcıda izin verin.'});
    return;
  }
  let config;
  try {
    config=(await requestPortal()).sources;
    await record('admin',{message:'Yönetici Portalı bağlı.'});
  } catch (error) {
    await record('admin',{message:error.message});
    return;
  }
  for (const provider of providers) {
    if (!(await chrome.storage.local.get('enabled')).enabled) return;
    const source=config?.[provider];
    if (!source) {
      await record(provider,{message:'Bu pazaryeri için aktif mağaza bağlantısı yok.'});
      continue;
    }
    try {
      if (provider==='pttavm') await readSeller('pttavmIdentity',source.identity);
      const {rows,observedAt}=await readSeller(provider,source.identity);
      if (!(await chrome.storage.local.get('enabled')).enabled) return;
      const result=await requestPortal({provider,...source,rows,observedAt});
      await record(provider,{message:result.count ? result.count+' kayıt aktarıldı.' : 'Yaklaşan ödeme kaydı bulunmadı.',
        lastSuccess:observedAt});
    } catch (error) {
      await record(provider,{message:error.message||'Pazaryeri okunamadı. Yeniden denenecek.'});
    }
  }
}

function sync() {
  if (!running) running=cycle().catch(()=>record('admin',{message:'Aktarım yeniden denenecek.'}))
    .finally(()=>{running=null;});
  return running;
}

chrome.runtime.onStartup.addListener(()=>{void schedule().then(sync);});
chrome.runtime.onInstalled.addListener(()=>{void schedule();});
chrome.alarms.onAlarm.addListener(event=>{if (event.name===alarm) void sync();});
chrome.runtime.onMessage.addListener((message,sender,respond) => {
  if (sender.id!==chrome.runtime.id || sender.url!==chrome.runtime.getURL('popup.html')) return false;
  (async()=>{
    if (message?.action==='enabled') {
      await chrome.storage.local.set({enabled:message.value===true});
      if (message.value===true) {
        await schedule();
        void sync();
      } else await chrome.alarms.clear(alarm);
    } else if (message?.action==='sync') {
      void sync();
    } else if (message?.action==='open' && ['admin',...providers].includes(message.site)) {
      await openSite(message.site);
    }
    return {ok:true};
  })().then(respond,()=>respond({ok:false}));
  return true;
});

const sources = {
  admin:'Yönetici Portalı',
  pttavm:'PTT AVM',
  idefix:'idefix',
  koctas:'Koçtaş',
};
const enabled = document.querySelector('#enabled');
const sync = document.querySelector('#sync');
const list = document.querySelector('#sources');
const notice = document.querySelector('#notice');
const access = {origins:chrome.runtime.getManifest().optional_host_permissions};
const time = new Intl.DateTimeFormat('tr-TR',{
  timeZone:'Europe/Istanbul',day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit',
});

async function action(data) {
  try {
    if (!(await chrome.runtime.sendMessage(data))?.ok) throw new Error();
    return true;
  } catch {
    notice.textContent='İşlem başlatılamadı. Tekrar deneyin.';
    return false;
  }
}

const statusFields = {};
for (const [source,label] of Object.entries(sources)) {
  const row=document.createElement('li');
  const title=document.createElement('strong');
  title.textContent=label;
  const open=document.createElement('button');
  open.textContent='Aç';
  open.type='button';
  open.setAttribute('aria-label',label+' ekranını aç');
  open.onclick=()=>void action({action:'open',site:source});
  const status=document.createElement('span');
  status.className='status';
  row.append(title,open,status);
  list.append(row);
  statusFields[source]=status;
}

async function render() {
  const current=await chrome.storage.local.get(['enabled','statuses']);
  enabled.checked=current.enabled===true;
  sync.disabled=!enabled.checked;
  for (const [source,el] of Object.entries(statusFields)) {
    const info=current.statuses?.[source];
    const last=info?.lastSuccess ? ' · Son başarılı okuma '+time.format(info.lastSuccess*1000) : '';
    el.textContent=(info?.message??'İlk okuma bekleniyor.')+last;
  }
}

enabled.onchange=async()=>{
  const requested=enabled.checked;
  try {
    if (requested && !await chrome.permissions.request(access)) {
      notice.textContent='Aktarım için satıcı panellerini okuma izni gerekiyor.';
      return;
    }
    if (await action({action:'enabled',value:requested})) {
      notice.textContent=requested?'Otomatik kontrol 15 dakikada bir çalışır.':'Otomatik aktarım durduruldu.';
      if (!requested) await chrome.permissions.remove(access);
    }
  } catch {
    notice.textContent='Tarayıcı izni güncellenemedi.';
  } finally {
    await render();
  }
};
sync.onclick=async()=>{
  if (await action({action:'sync'})) notice.textContent='Ödeme panelleri kontrol ediliyor.';
};
chrome.storage.onChanged.addListener((_,area)=>{if(area==='local') void render();});
await render();

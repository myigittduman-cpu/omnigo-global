const admin = 'https://yonetim.omnigoglobal.com';
const locations = {
  admin:admin+'/',
  pttavmIdentity:'https://merchant.pttavm.com/',
  pttavm:'https://merchant.pttavm.com/accounting/payoff/future-payments',
  idefix:'https://satici.idefix.com/finance/future-estimated-payments',
  koctas:'https://satisortagim.koctas.com.tr/pazaryerisatici/odeme-ve-finans/odemeler/gelecek-odemelerim',
};

const siteOrigin = url => new URL(url).origin;
const sitePage = url => siteOrigin(url)+new URL(url).pathname;
const wait = duration => new Promise(resolve=>setTimeout(resolve,duration));
const needsLogin = (id,url) => {
  if (!url || siteOrigin(url)!==siteOrigin(locations[id])) return true;
  return /(?:login|magaza-giris|oturum|sign-in|signin)(?:\/|$)/i.test(new URL(url).pathname);
};

async function find(id) {
  const storage = await chrome.storage.session.get(id);
  if (!Number.isInteger(storage[id])) return null;
  const tab=await chrome.tabs.get(storage[id]).catch(()=>null);
  if (!tab || !tab.url || siteOrigin(tab.url)!==siteOrigin(locations[id])) return null;
  return tab;
}

async function getTab(id) {
  const saved=await find(id);
  if (saved) return saved;
  if (id==='admin') {
    const matches=await chrome.tabs.query({url:admin+'/*'});
    if (matches.length) {
      await chrome.storage.session.set({admin:matches[0].id});
      return matches[0];
    }
  }
  const created=await chrome.tabs.create({active:false,url:locations[id]});
  await chrome.storage.session.set({[id]:created.id});
  return created;
}

async function ready(tabId) {
  const expiry=Date.now()+20000;
  do {
    const tab=await chrome.tabs.get(tabId);
    if (tab.status==='complete' && tab.url && !tab.pendingUrl) return tab;
    await wait(250);
  } while (Date.now()<expiry);
  throw new Error('Ödeme sayfası zamanında açılmadı.');
}

export async function openSite(id) {
  if (!(id in locations) || id==='pttavmIdentity') return;
  const tab=await getTab(id);
  await chrome.tabs.update(tab.id,{active:true});
}

export async function readSeller(id, identity) {
  const selected=await getTab(id);
  let tab=await ready(selected.id);
  if (needsLogin(id,tab.url)) throw new Error('Satıcı panelinde oturum açın.');
  if (sitePage(tab.url)!==locations[id]) await chrome.tabs.update(tab.id,{url:locations[id]});
  else if (selected.status==='complete') await chrome.tabs.reload(tab.id,{bypassCache:true});
  tab=await ready(tab.id);
  if (needsLogin(id,tab.url) || sitePage(tab.url)!==locations[id]) throw new Error('Satıcı panelinde oturum açın.');
  const target={tabId:tab.id};
  await chrome.scripting.executeScript({target,files:['panel-format.js','panel-reader.js']});
  const [{result}] = await chrome.scripting.executeScript({
    target,args:[id,identity],
    func:(reader,expected) => globalThis.omnigoReadPayouts(reader,expected),
  });
  if (!result?.ok) throw new Error(result?.message??'Ödeme verisi okunamadı.');
  return result;
}

export async function requestPortal(data=null) {
  const tab=await ready((await getTab('admin')).id);
  if (siteOrigin(tab.url)!==admin) throw new Error('Yönetici Portalı’nda oturum açın.');
  const [{result}] = await chrome.scripting.executeScript({
    target:{tabId:tab.id},
    args:[data,admin],
    func:async (snapshot,origin) => {
      if (location.origin!==origin) return {status:401};
      const headers={'Accept':'application/json'};
      if (snapshot) {
        const session=await fetch('/api/me',{credentials:'same-origin',cache:'no-store',signal:AbortSignal.timeout(10000)});
        if (!session.ok) return {status:session.status};
        const account=await session.json();
        if (account.user?.role!=='admin' || typeof account.csrf!=='string') return {status:403};
        headers['X-CSRF-Token']=account.csrf;
        headers['Content-Type']='application/json';
      }
      const response=await fetch('/api/payments/upcoming-panel',{
        credentials:'same-origin',cache:'no-store',headers,
        method:snapshot?'POST':'GET',body:snapshot?JSON.stringify(snapshot):undefined,
        signal:AbortSignal.timeout(12000),
      });
      return response.ok ? {status:response.status,body:await response.json()} : {status:response.status};
    },
  });
  if (result?.status===401 || result?.status===403) throw new Error('Yönetici Portalı’nda oturum açın.');
  if (result?.status===409) throw new Error('Pazaryeri bağlantısı değişti. Yeniden kontrol edilecek.');
  if (result?.status!==200) throw new Error('Yönetici Portalı ödeme aktarımını kabul etmedi.');
  return result.body;
}

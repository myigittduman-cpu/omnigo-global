import {bindCredentialEditor} from './credential-editor.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {api} from './api.js';
import {openSupportExample} from './support-example.js';
import {setupProviders} from './customer-api-help.js';
import {connectionForm} from './customer-setup-view.js';

export async function mountSetupConnections(root,{company,markets,current,onChange,plan=null}){
 const selected=markets.filter(m=>m.selected),statuses=new Map();
 const results=await Promise.allSettled(selected.map(m=>plan?{configured:m.configured,version:0}:api(`/integrations/${m.provider}?company=${company.id}`)));
 if(!current())return;
 results.forEach((result,index)=>statuses.set(selected[index].provider,result.status==='fulfilled'?result.value:{error:result.reason.message}));
 if(!root.querySelector('[data-setup-provider]'))root.innerHTML='';
 for(const market of selected)if(!root.querySelector(`[data-setup-provider="${market.provider}"]`))root.insertAdjacentHTML('beforeend',connectionForm(setupProviders.find(p=>p.key===market.provider),market,statuses.get(market.provider),company,{deferred:!!plan}));
 if(!selected.length)root.textContent=i18nT('Seçilmiş pazaryeri yok. Geri düğmesiyle mağaza seçebilir veya kurulumu tamamlayabilirsiniz.');
 for(const card of root.querySelectorAll('[data-setup-provider]')){
  if(card.dataset.mounted)continue;card.dataset.mounted='true';
  const key=card.dataset.setupProvider,market=selected.find(m=>m.provider===key),url=`/integrations/${key}?company=${company.id}`;
  const status=card.querySelector('[data-connection-status]'),message=card.querySelector('[data-provider-message]');
  const display=()=>{const value=statuses.get(key);status.textContent=value.error?i18nT('Durum alınamadı'):plan?.has(key)?i18nT('Aktarım için hazır'):value.configured?i18nT('API kayıtlı · aktarım durumu son kontrolde'):i18nT('API bilgileri bekleniyor');if(value.error)message.textContent=value.error;};display();
  for(const button of card.querySelectorAll('[data-template]'))button.onclick=()=>openSupportExample(button,setupProviders.find(p=>p.key===key),company.name,market.storeName,company.marketplaceStockPilot===true);
  if(plan&&market.configured)continue;
  const run=async(form,work)=>{
   const button=form.querySelector('[type=submit]'),error=form.querySelector('[data-provider-error]');
   if(button.disabled)return false;button.disabled=true;message.textContent='';error.textContent='';
   try{if(!current())return false;await work();return true;}
   // Keep save errors in the submitting form so the unsaved-changes guard can show the same reason.
   catch(failure){if(current())error.textContent=failure.message;return false;}
   finally{button.disabled=false;}
  };
  const store=card.querySelector('[data-setup-store]');
  store.onsubmit=async event=>{event.preventDefault();return run(store,async()=>{
   const name=store.elements.storeName.value.trim();
   await api(`/management/${plan?'setup/':''}marketplaces/${key}?company=${company.id}`,'POST',{action:'edit',storeName:name,version:market.version});
   market.version++;market.storeName=name;if(current())message.textContent=i18nT('Mağaza adı kaydedildi.');onChange();
  });};
  const credentials=card.querySelector('[data-setup-credentials]');
  const editor=bindCredentialEditor(credentials,{provider:key,company:company.id,status:statuses.get(key),current,onVersion:version=>{statuses.get(key).version=version;}});
  credentials.onsubmit=async event=>{event.preventDefault();return run(credentials,async()=>{
   const prior=statuses.get(key);if(prior.error)throw new Error(i18nT('Bağlantı sürümü alınamadı. Kurulumu yeniden açıp tekrar deneyin.'));
   if(!market.storeName)throw new Error(i18nT('Önce mağaza adını kaydedin.'));
   const values=Object.fromEntries(new FormData(credentials));for(const field of Object.keys(values))values[field]=values[field].trim();
   if(key==='koctas')values.shopId=Number(values.shopId);
   if(plan){
    plan.prepare(key,{...values,version:prior.version,marketVersion:market.version});
    credentials.reset();display();
    message.textContent=i18nT('API bilgileri hazır. Son kontrolde “Canlı Aktarımı Başlat” düğmesine basın.');
    return;
   }
   await api(url,'POST',{...values,version:prior.version});credentials.reset();market.configured=true;onChange();
   try{statuses.set(key,await api(url));}catch(error){statuses.set(key,{error:error.message});}
   if(current()){editor.reset();display();message.textContent=i18nT('API bilgileri kaydedildi. Otomatik aktarım kontrolü sıraya alındı; son kontrolden sonucu izleyin.');}
  });};
 }
}

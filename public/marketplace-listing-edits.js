import {api} from './api.js';
import {esc,openDialog,platform} from './ui.js';

const providers=['Hepsiburada','n11','idefix','PTT AVM','Pazarama','Koçtaş','Trendyol'];
const labels={
 loading:'Kontrol ediliyor',waiting:'Sırası bekleniyor',sending:'Aktarılıyor',
 queued:'Sırada',accepted:'Onay bekleniyor',checking:'Doğrulanıyor',succeeded:'Doğrulandı',
 failed:'Başarısız',uncertain:'Kontrol gerekiyor',missing:'Bu pazaryerinde aktif ilan yok',
 unverified:'Aktif ilan doğrulanamadı',ineligible:'Aktif ilan var, gönderime uygun değil',unconnected:'Pazaryeri bağlantısı yok',
};
const failed=new Set(['failed','uncertain','missing','unverified','ineligible','unconnected']);

export function parsePrice(text){
 if(!/^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?$/.test(text))return null;
 const [whole,decimal='']=text.replaceAll('.','').split(',');
 const value=Number(whole)*100+Number(decimal.padEnd(2,'0'));
 return Number.isSafeInteger(value)&&value>0&&value<=999999999999?value:null;
}

export function listingTransferLines(lines=[]){
 const byProvider=new Map(lines.map(line=>[line.provider,line]));
 return providers.map(provider=>{
  const {state='loading',details=[],message}=byProvider.get(provider)??{};
  const mark=state==='succeeded'?'<b class="listing-success" aria-hidden="true">✓</b>':failed.has(state)?'<b class="listing-failure" aria-hidden="true">✕</b>':'';
  const summary=message||details.map(operation=>[(operation.kind==='stock'?'Stok':'Fiyat'),labels[operation.state],operation.message].filter(Boolean).join(' · ')).join(' / ');
  return `<li class="listing-transfer-line" data-state="${esc(state)}">${platform(provider)}
    <span class="listing-transfer-mark">${mark}</span><span><strong>${esc(labels[state])}</strong>${summary?`<small>${esc(summary)}</small>`:''}</span></li>`;
 }).join('');
}

export function bindListingEdits(root,{company,current,itemFor,refresh}){
 const path='/inventory/active-marketplace-stock?'+new URLSearchParams({company});
 const posting=new Set();
 function open(item){
  const dialog=openDialog('Pazaryeri aktarım durumu',`<p class="listing-transfer-title">${esc(item.title||item.sku)}</p>
   <p class="listing-transfer-message" role="status"></p><ul class="listing-transfer-lines">${listingTransferLines()}</ul>`,{className:'listing-transfer-dialog'});
  const list=dialog.querySelector('.listing-transfer-lines'),message=dialog.querySelector('.listing-transfer-message');
  const visible=()=>current()&&list.isConnected&&dialog.open;
  const paint=result=>{if(visible())list.innerHTML=listingTransferLines(result.lines);};
  const feedback=value=>{if(visible())message.textContent=value;};
  return {visible,paint,feedback};
 }
 async function follow(item,surface,result){
  const requestId=result?.requestId;
  const href=path+'&'+new URLSearchParams({transfer:item.cardKey,...(requestId?{requestId}:{})});
  while(surface.visible()){
   result??=await api(href);
   surface.paint(result);
   if(result.done){
    surface.feedback(result.requestId?'Son aktarım sonucu':'Bu stok kartında henüz aktarım yapılmadı.');
    if(requestId)await refresh();
    return;
   }
   surface.feedback('Pazaryerleri sırayla işleniyor.');
   await new Promise(done=>setTimeout(done,4500));
   result=null;
  }
 }
 async function report(item){
  const surface=open(item);
  try{await follow(item,surface);}
  catch(error){surface.feedback(error.message);}
 }
 async function save(row,item){
  const key=item.cardKey;
  if(!item.writeTarget||posting.has(key))return;
  const stock=row.querySelector('[data-quantity]'),price=row.querySelector('[data-price]');
  const changeStock=stock.value!==stock.defaultValue,changePrice=price.value!==price.defaultValue;
  const quantity=Number(stock.value),priceCents=parsePrice(price.value.trim());
  const invalid=changeStock&&(!/^\d+$/.test(stock.value)||!Number.isSafeInteger(quantity)||quantity>100000000)||changePrice&&priceCents===null;
  const surface=open(item);
  if(invalid||!changeStock&&!changePrice){
   try{surface.paint(await api(path+'&'+new URLSearchParams({transfer:key})));}
   catch(error){surface.feedback(error.message);return;}
   surface.feedback(invalid?'Stok için bir tam sayı, fiyat için 1.234,56 biçiminde pozitif bir tutar girin.':'Göndermek için stok veya satış fiyatını değiştirin.');
   return;
  }
  const button=row.querySelector('[data-save-listing]');
  posting.add(key);button.disabled=true;
  let result;
  try{
   result=await api(path,'POST',{action:'transfer',confirmed:true,cardKey:key,requestId:crypto.randomUUID(),
    ...(changeStock?{quantity}:{}),...(changePrice?{priceCents}:{})},{confirmed:true});
   if(changeStock)stock.defaultValue=stock.value;
   if(changePrice)price.defaultValue=price.value;
   delete row.dataset.editing;
  }catch(error){surface.feedback(error.message);return;}
  finally{posting.delete(key);button.disabled=false;}
  try{await follow(item,surface,result);}
  catch(error){surface.feedback(error.message);}
 }
 root.addEventListener('input',event=>{
  if(event.target.matches('[data-quantity],[data-price]'))event.target.closest('tr').dataset.editing='true';
 });
 root.addEventListener('click',event=>{
  const button=event.target.closest('[data-transfer-report],[data-save-listing]');
  if(!button||!current())return;
  const row=button.closest('tr'),item=itemFor(row.dataset.listing);
  if(!item?.cardKey)return;
  if(button.hasAttribute('data-transfer-report'))void report(item);
  else void save(row,item);
 });
 return {busy:()=>posting.size>0||Boolean(root.querySelector('[data-editing],tbody input:focus'))};
}

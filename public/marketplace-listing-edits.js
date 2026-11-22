import {api} from './api.js';
import {esc,openDialog} from './ui.js';

const states={queued:'Sırada',sending:'Gönderiliyor',accepted:'Onay bekleniyor',checking:'Doğrulanıyor',succeeded:'Doğrulandı',failed:'Başarısız',uncertain:'Kontrol gerekiyor',missing:'Bu pazaryerinde aktif ilan yok',unverified:'Katalog veya ilan eşleşmesi doğrulanamadı',ineligible:'İlan var, gönderime uygun değil',same:'Stok zaten aynı',busy:'Önceki işlem sürüyor'};
const pending=state=>['queued','sending','accepted','checking'].includes(state);
export function parsePrice(text){
 if(!/^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?$/.test(text))return null;
 const [whole,decimal='']=text.replaceAll('.','').split(',');
 const value=Number(whole)*100+Number(decimal.padEnd(2,'0'));
 return Number.isSafeInteger(value)&&value>0&&value<=999999999999?value:null;
}

export function bindListingEdits(root,{company,user,current,itemFor,refresh}){
 const suffix='?company='+encodeURIComponent(company),path='/inventory/active-marketplace-stock'+suffix;
 const storageKey='listing-transfers:'+user+':'+company;
 let saved={};try{saved=JSON.parse(localStorage.getItem(storageKey)||'{}')||{};}catch{}
 const messages=new Map(Object.entries(saved));
 let dialogBody,dialogKey;
 function paintDialog(lines){
  if(!dialogBody?.isConnected)return;
  dialogBody.innerHTML=lines.map(({provider,kind,state,message})=>{
   const icon=state==='succeeded'?'<b class="listing-success">✓</b>':['failed','missing','ineligible'].includes(state)?'<b class="listing-failure">✕</b>':'';
   return '<div class="listing-transfer-line">'+icon+(provider?'<strong>'+esc(provider)+'</strong>':'')+'<span>'+esc([kind,states[state],message].filter(Boolean).join(' · '))+'</span></div>';
  }).join('');
 }
 function open(row){
  dialogKey=row.dataset.listing;
  const item=itemFor(dialogKey);
  const dialog=openDialog('Pazaryeri aktarım durumu','<p>'+esc(item?.title||item?.sku||'')+'</p><div data-transfer-body role="status"></div>',{className:'listing-transfer-dialog'});
  dialogBody=dialog.querySelector('[data-transfer-body]');
  paintDialog(messages.get(dialogKey)||[{message:'Pazaryerleri kontrol ediliyor…'}]);
  if(!row.dataset.saving)void resume(row,dialogBody);
 }
 function show(row,lines){
  const key=row.dataset.listing;
  messages.set(key,lines);
  try{localStorage.setItem(storageKey,JSON.stringify(Object.fromEntries(messages)));}catch{}
  row.querySelector('[data-results]').innerHTML='<button type="button" class="btn" data-transfer-report>Aktarım durumu</button>';
  if(key===dialogKey)paintDialog(lines);
 }
 async function resume(row,body){
  const key=row.dataset.listing;
  const visible=()=>current()&&body.isConnected&&body.closest('dialog')?.open;
  while(visible()){
   const lines=messages.get(key);
   if(!lines?.some(line=>pending(line.state)&&(line.id||line.priceId)))return;
   try{
    const prices=new Set(lines.filter(line=>pending(line.state)&&line.priceId).map(line=>line.priceId));
    for(const line of lines.filter(line=>pending(line.state)&&line.id)){
     const result=await api(path+'&requestId='+encodeURIComponent(line.id));
     Object.assign(line,{state:result.state,message:result.error});
    }
    for(const priceId of prices){
     const {results}=await api(path+'&priceRequest='+encodeURIComponent(priceId));
     lines.splice(0,lines.length,...lines.filter(line=>line.priceId!==priceId),...results.map(line=>({...line,priceId,kind:'Fiyat'})));
    }
    if(!visible()||messages.get(key)!==lines)return;
    show(row,lines);
   }catch(error){if(visible())paintDialog([...lines,{message:error.message}]);return;}
   if(lines.some(line=>pending(line.state)))await new Promise(done=>setTimeout(done,4500));
  }
 }
 function restore(){
  for(const row of root.querySelectorAll('[data-listing]')){
   const lines=messages.get(row.dataset.listing);
   if(lines)show(row,lines);
  }
 }
 async function save(row,item){
  if(row.dataset.saving||!item?.writeTarget)return;
  const stock=row.querySelector('[data-quantity]'),price=row.querySelector('[data-price]');
  const changeStock=stock.value!==stock.defaultValue,changePrice=price.value!==price.defaultValue;
  if(!changeStock&&!changePrice){if(!messages.has(row.dataset.listing))show(row,[{message:'Göndermek için stok veya satış fiyatını değiştirin.'}]);open(row);return;}
  open(row);
  const quantity=Number(stock.value),priceCents=parsePrice(price.value.trim());
  if(changeStock&&(!/^\d+$/.test(stock.value)||!Number.isSafeInteger(quantity)||quantity>100000000)||changePrice&&priceCents===null){
   show(row,[{message:'Stok için bir tam sayı, fiyat için 1.234,56 biçiminde pozitif bir tutar girin.'}]);return;
  }
  row.dataset.saving='true';
  const controls=row.querySelectorAll('input,button');
  controls.forEach(el=>el.disabled=true);
  const lines=[];let priceId;
  const alive=()=>current()&&row.isConnected;
  show(row,[{message:'Pazaryerleri kontrol ediliyor…'}]);
  try{
   const key=item.writeTarget.productKey;
   const {targets,unavailable=[]}=await api('/inventory/active-marketplace-listings'+suffix+'&productKey='+encodeURIComponent(key));
   if(!alive())return;
   lines.push(...unavailable);
   if(changeStock){
    const send=[];
    for(const t of targets){
     if(t.requestId||t.quantity===quantity)lines.push({provider:t.provider,kind:'Stok',state:t.requestId?'busy':'same'});
     else send.push({requestId:crypto.randomUUID(),provider:t.provider,listingKey:t.key,sourceQuantity:t.quantity,identityToken:t.identityToken});
    }
    if(!targets.length)lines.push({kind:'Stok',message:'Uygun aktif ilan yok.'});
    if(send.length){
     const {results}=await api(path,'POST',{action:'group',confirmed:true,productKey:key,quantity,targets:send},{confirmed:true});
     results.forEach((r,i)=>lines.push({provider:send[i].provider,kind:'Stok',state:r.error?'failed':r.state,message:r.error,id:r.requestId}));
     show(row,lines);
    }
   }
   if(changePrice&&alive()){
    priceId=crypto.randomUUID();
    const {results}=await api(path,'POST',{action:'price',confirmed:true,requestId:priceId,productKey:key,priceCents},{confirmed:true});
    results.forEach(r=>lines.push({...r,priceId,kind:'Fiyat'}));show(row,lines);
   }
   while(alive()){
    show(row,lines);
    if(!lines.some(r=>pending(r.state)))break;
    await new Promise(done=>setTimeout(done,4500));
    if(!alive())return;
    for(const line of lines.filter(r=>r.id&&pending(r.state))){
     const r=await api(path+'&requestId='+encodeURIComponent(line.id));
     line.state=r.state;line.message=r.error;
    }
    if(priceId&&lines.some(r=>r.kind==='Fiyat'&&pending(r.state))){
     const {results}=await api(path+'&priceRequest='+priceId);
     lines.splice(0,lines.length,...lines.filter(r=>r.kind!=='Fiyat'),...results.map(r=>({...r,priceId,kind:'Fiyat'})));
    }
   }
   if(alive()){delete row.dataset.editing;await refresh();}
  }catch(error){if(alive())show(row,[...lines,{message:error.message}]);}
  finally{delete row.dataset.saving;controls.forEach(el=>el.disabled=false);}
 }
 root.addEventListener('input',e=>{if(e.target.matches('[data-quantity],[data-price]'))e.target.closest('tr').dataset.editing='true';});
 root.addEventListener('click',e=>{
  const report=e.target.closest('[data-transfer-report]');
  if(report&&current()){open(report.closest('tr'));return;}
  const row=e.target.closest('[data-save-listing]')?.closest('tr');
  if(row&&current())void save(row,itemFor(row.dataset.listing));
 });
 return {restore,busy:()=>Boolean(root.querySelector('[data-saving],[data-editing],tbody input:focus'))};
}
import {esc,platform} from './ui.js';
import {displayMoneyAmount} from './money-value.js';

const providers=['Hepsiburada','n11','idefix','PTT AVM','Pazarama','Koçtaş','Trendyol'];
const descriptions={
 loading:'Kontrol ediliyor',waiting:'Sırası bekleniyor',sending:'Aktarılıyor',skipped:'Gönderim seçilmedi',
 queued:'Sırada',accepted:'Onay bekleniyor',checking:'Doğrulanıyor',succeeded:'Doğrulandı',
 failed:'Aktarılamadı',uncertain:'Sonuç doğrulanamadı',missing:'Aktif ilan bulunamadı',
 unverified:'Katalog güncelliği doğrulanamadı',ineligible:'İlan güncellemeye uygun değil',unconnected:'Pazaryeri bağlantısı yok',
};

export function listingTransferStatus(line,{started=false}={}){
 let label=descriptions[line.state]||descriptions.loading;
 if(!started){
  if(line.closed&&line.state==='missing')label='Satışa kapalı';
  if(line.state==='waiting')label='Güncellemeye hazır';
 }else if(line.state==='ineligible'&&line.closed)label='Satışa kapalı';
 const positive=line.state==='succeeded';
 const negative=started&&['failed','uncertain','missing','unverified','ineligible','unconnected'].includes(line.state);
 const mark=positive?'<b class="listing-success" aria-hidden="true">✓</b>':negative?'<b class="listing-failure" aria-hidden="true">✕</b>':'';
 const details=line.message||line.details?.map(operation=>[(operation.kind==='stock'?'Stok':'Satış fiyatı'),descriptions[operation.state],operation.message]
  .filter(Boolean).join(' · ')).join(' / ');
 return `<span class="listing-transfer-mark">${mark}</span><span><strong>${esc(label)}</strong>${details?`<small>${esc(details)}</small>`:''}</span>`;
}

function listingInput(line,field,label,value){
 const editable=line.state==='waiting';
 const missing=value===''?'—':value;
 if(!editable)return esc(missing);
 return `<input type="text" name="${field}-${esc(line.provider)}" data-listing-field="${field}" inputmode="${field==='quantity'?'numeric':'decimal'}"
  autocomplete="off" aria-label="${esc(line.provider+' '+label)}" value="${esc(value)}" placeholder="—">`;
}

export function listingTransferLines(lines=[],purchasePriceCents=null){
 const byProvider=new Map(lines.map(line=>[line.provider,line]));
 return providers.map(provider=>{
  const line=byProvider.get(provider)??{provider,state:'loading'};
  return `<tr class="listing-transfer-line" data-channel="${esc(provider)}" data-state="${esc(line.state)}">
   <th scope="row">${platform(provider)}</th>
   <td data-listing-cost>${esc(displayMoneyAmount(purchasePriceCents)||'—')}</td>
   <td>${listingInput(line,'priceCents','satış fiyatı',displayMoneyAmount(line.priceCents))}</td>
   <td>${listingInput(line,'quantity','stok adedi',Number.isSafeInteger(line.quantity)?String(line.quantity):'')}</td>
   <td data-listing-state>${listingTransferStatus(line)}</td>
  </tr>`;
 }).join('');
}

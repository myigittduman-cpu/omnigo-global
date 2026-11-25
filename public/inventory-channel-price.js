import {api} from './api.js';
import {contentError,loadingState} from './content-state.js';
import {displayMoneyAmount,parseMoneyAmount} from './money-value.js';
import {esc,money,openDialog} from './ui.js';

const fields=[
 ['shippingNetCents','Kargo (KDV hariç, TL)'],
 ['otherNetCents','Diğer giderler (KDV hariç, TL)'],
 ['salesVatBasisPoints','Satış KDV’si (%)',true],
 ['effectiveCommissionBasisPoints','Etkin komisyon (%)',true],
 ['targetNetMarginBasisPoints','Hedef net kâr marjı (%)',true],
 ['roundUpCents','Yukarı yuvarlama adımı (TL)',false,true],
];
const reasons={
 catalog_price_unverified:'Güncel katalog fiyatı doğrulanamadı',
 net_purchase_cost_missing:'KDV hariç alış maliyeti gerekli',
 pricing_rule_missing:'Kanal fiyat kuralı gerekli',
};
const format=value=>Number.isSafeInteger(value)?money(value):'—';

function rows(data){
 return Object.entries(data.marketplaces).map(([provider,channel])=>
  '<tr><th scope="row">'+esc(provider)+'</th><td>'+format(channel.currentPriceCents)+'</td><td>'+format(channel.quote?.priceGrossCents)+
  '</td><td>'+format(channel.quote?.projectedNetProfitCents)+'</td><td>'+
  esc(channel.holds.map(hold=>reasons[hold]||hold).join(' · ')||'Hesaplandı')+'</td></tr>').join('');
}

function ruleFields(provider,index,rule={}){
 return '<fieldset class="form-grid full-width" data-price-rule="'+esc(provider)+'"><legend>'+esc(provider)+'</legend>'+
  fields.map(([key,label,rate])=>'<label>'+label+'<input type="text" inputmode="decimal" autocomplete="off" name="channel-'+index+'-'+key+
   '" data-price-field="'+key+'" value="'+displayMoneyAmount(rule[key],{rate})+'"></label>').join('')+'</fieldset>';
}

export async function openInventoryChannelPrice(item,{endpoint,current}){
 const dialog=openDialog('Pazaryeri fiyat önizlemesi',loadingState('Fiyat önizlemesi yükleniyor…'),
  {className:'inventory-dialog inventory-stock-dialog inventory-channel-price-dialog',loading:true});
 const body=dialog.querySelector('.dialog-body'),abort=new AbortController();
 const active=()=>current()&&dialog.open&&dialog.contains(body);
 dialog.addEventListener('close',()=>abort.abort(),{once:true});
 try{
  let data=await api(endpoint('/price-preview?'+new URLSearchParams({productKey:item.key})),'GET',undefined,{signal:abort.signal});
  if(!active())return;
  const providers=Object.keys(data.marketplaces);
  body.innerHTML='<p><strong>'+esc(item.title)+'</strong> <span class="muted">'+esc(data.sku)+'</span></p>'+
   '<div class="inventory-stock-details-wrap" tabindex="0" aria-label="Pazaryeri fiyat karşılaştırması"><table class="inventory-stock-details">'+
   '<thead><tr><th scope="col">Pazaryeri</th><th scope="col">Mevcut fiyat</th><th scope="col">Önerilen fiyat</th><th scope="col">Tahmini net kâr</th><th scope="col">Durum</th></tr></thead>'+
   '<tbody>'+rows(data)+'</tbody></table></div>'+
   '<form class="form-grid" data-channel-price-form><label class="full-width">Önizleme alış maliyeti (KDV hariç, TL)'+
   '<input name="cost" type="text" inputmode="decimal" autocomplete="off" value="'+displayMoneyAmount(data.draftCostNetCents)+
   '" placeholder="'+displayMoneyAmount(data.netPurchaseCostCents)+'"></label>'+
   providers.map((provider,index)=>ruleFields(provider,index,data.rules[provider])).join('')+
   '<p class="muted full-width">'+(providers.length?'Etkin komisyonu KDV dahil satış fiyatına uygulanacak net kesinti oranı olarak girin. Yuvarlama boşsa kuruşa yuvarlanır.':'Bu karta bağlı aktif pazaryeri ilanı bulunamadı.')+'</p>'+
   '<p class="form-error full-width" role="alert"></p><p class="muted full-width" data-price-result role="status"></p>'+
   '<div class="dialog-actions full-width"><button type="submit" class="btn primary">Hesapla ve kaydet</button></div></form>';
  const form=body.querySelector('form'),error=form.querySelector('.form-error'),submit=form.querySelector('[type=submit]');
  form.onsubmit=async event=>{
   event.preventDefault();if(!active()||submit.disabled)return false;
   submit.disabled=true;error.textContent='';form.querySelector('[data-price-result]').textContent='';
   try{
    const rules={...data.rules};
    for(const group of form.querySelectorAll('[data-price-rule]')){
     const provider=group.dataset.priceRule;
     const inputs=[...group.querySelectorAll('input')];
     if(inputs.every(input=>!input.value.trim())){delete rules[provider];continue;}
     rules[provider]=Object.fromEntries(fields.flatMap(([key,,rate,optional],index)=>{
      const value=parseMoneyAmount(inputs[index].value,{rate,optional});
      return value===null?[]:[[key,value]];
     }));
    }
    const costNetCents=parseMoneyAmount(form.elements.cost.value,{optional:true});
    data=await api(endpoint('/price-preview'),'POST',{productKey:item.key,version:data.version,costNetCents,rules});
    if(!active())return false;
    body.querySelector('tbody').innerHTML=rows(data);
    form.querySelector('[data-price-result]').textContent='Fiyat önizlemesi kaydedildi.';
    return true;
   }catch(failure){if(active())error.textContent=failure.message;return false;}
   finally{submit.disabled=false;}
  };
  document.dispatchEvent(new Event('omnigo-dialog-ready'));
 }catch(error){if(active()){body.innerHTML=contentError(error.message);document.dispatchEvent(new Event('omnigo-dialog-ready'));}}
}

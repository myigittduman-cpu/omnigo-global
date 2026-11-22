import {api} from './api.js';
import {contentError,loadingState} from './content-state.js';
import {currentLocale,html,t} from './i18n.js';
import {date,esc,now,openDialog,toast} from './ui.js';

const endpoint='/finance/profitability/commission-rates';

function rateHistory(rates) {
  if(!rates.length)return html`<p class="muted">Kayıtlı manuel oran yok.</p>`;
  const percent=new Intl.NumberFormat(currentLocale(),{style:'percent',maximumFractionDigits:2});
  return html`<div class="profitability-rates-scroll"><table>
    <thead><tr><th>Pazaryeri / SKU</th><th>Geçerlilik tarihi</th><th>Oran</th><th scope="col"><span class="sr-only">İşlem</span></th></tr></thead>
    <tbody>${rates.map((rate,index)=>html`<tr>
      <td>${esc(rate.channel)}<small>${esc(rate.sku||t('Genel oran'))}</small></td>
      <td><time datetime="${esc(rate.effectiveFrom)}">${esc(date(rate.effectiveFrom))}</time></td>
      <td>${esc(percent.format(rate.basisPoints/10000))}</td>
      <td><button class="btn" type="button" data-edit-commission="${index}">Düzenle</button></td>
    </tr>`).join('')}</tbody>
  </table></div>`;
}

export async function openProfitabilityRates({company,channels,channel,valid,refresh}) {
  if(!valid()||!channels.length)return;
  const dialog=openDialog(t('Manuel komisyon oranları'),html`
    <form class="form-grid profitability-rates-form">
      <p class="full-width muted">Manuel oran satış tutarına uygulanır. Diğer kesintilerin veya KDV toplamının teyidi değildir; ekstreyle eşleşmeden nihai tutar oluşturmaz.</p>
      <label>Pazaryeri<select name="channel" required>${channels.map(name=>html`
        <option value="${esc(name)}" ${name===channel?'selected':''}>${esc(name)}</option>`).join('')}</select></label>
      <label>SKU (isteğe bağlı)<input name="sku" maxlength="180" autocomplete="off" placeholder="Tüm ürünler"></label>
      <label>Geçerlilik tarihi<input type="date" name="effectiveFrom" required value="${now()}"></label>
      <label>Komisyon (%)<input name="percentage" inputmode="decimal" maxlength="6" autocomplete="off" placeholder="12,50" required></label>
      <p class="form-error full-width" role="alert"></p>
      <div class="form-actions full-width"><button type="submit" class="btn primary" disabled>Kaydet</button></div>
    </form>
    <section class="profitability-rates-history" aria-label="Kayıtlı komisyon oranları" aria-live="polite">
      ${loadingState('Oranlar yükleniyor…',{compact:true})}
    </section>`,{className:'profitability-rates-dialog'});
  const form=dialog.querySelector('form');
  const alert=form.querySelector('[role="alert"]');
  const submit=form.querySelector('button[type="submit"]');
  const history=dialog.querySelector('.profitability-rates-history');
  const controller=new AbortController();
  const active=()=>dialog.open&&form.isConnected&&valid()&&!controller.signal.aborted;
  dialog.addEventListener('close',()=>controller.abort(),{once:true});
  let rates=[];
  const display=next=>{rates=next;history.innerHTML=rateHistory(rates);};
  history.addEventListener('click',event=>{
    const button=event.target.closest('[data-edit-commission]');
    if(!button||submit.disabled)return;
    const rate=rates[Number(button.dataset.editCommission)];
    if(!rate||!channels.includes(rate.channel))return;
    form.elements.channel.value=rate.channel;
    form.elements.sku.value=rate.sku;
    form.elements.effectiveFrom.value=rate.effectiveFrom;
    form.elements.percentage.value=String(rate.basisPoints/100).replace('.',',');
    form.elements.percentage.focus();
  });
  form.onsubmit=async event=>{
    event.preventDefault();
    if(!active()||submit.disabled||!form.reportValidity())return false;
    const {channel,sku,effectiveFrom,percentage}=Object.fromEntries(new FormData(form));
    const value=percentage.trim();
    const basisPoints=Math.round(Number(value.replace(',','.'))*100);
    if(!/^\d{1,3}(?:[.,]\d{1,2})?$/.test(value)||basisPoints>10000){
      alert.textContent=t('0 ile 100 arasında bir komisyon yüzdesi girin.');
      return false;
    }
    submit.disabled=true;
    try{
      const result=await api(endpoint,'POST',{company,channel,sku,effectiveFrom,basisPoints},{signal:controller.signal});
      if(!active())return false;
      display(result.rates);
      toast('Komisyon oranı kaydedildi.');
      void refresh();
      return true;
    }catch(error){
      if(active())alert.textContent=error.message;
      return false;
    }finally{if(active())submit.disabled=false;}
  };
  try{
    const result=await api(endpoint+'?'+new URLSearchParams({company}),'GET',null,{signal:controller.signal});
    if(active()){display(result.rates);submit.disabled=false;}
  }catch(error){if(active())history.innerHTML=contentError(error.message);}
}

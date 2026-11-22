import {api} from './api.js';
import {html,t,text} from './i18n.js';

function validDay(day){
 if(typeof day!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(day))return false;
 const value=new Date(day+'T00:00:00Z');
 return !Number.isNaN(value.valueOf())&&value.toISOString().slice(0,10)===day;
}
const displayDay=day=>day.split('-').reverse().join('.');
const states={
 queued:'Geçmiş sipariş aktarımı sırada.',
 running:'Geçmiş siparişler kontrol ediliyor.',
 verified:'İstenen dönemin aktarımı doğrulandı.',
 incomplete:'İstenen dönemde eksik kalan siparişler var. Yeniden kontrol edebilirsiniz.',
 failed:'İstenen dönemin aktarımı tamamlanamadı. Yeniden deneyebilirsiniz.'
};

export function pazaramaHistoryState(connection){
 const history=connection?.history,version=connection?.version;
 if(!connection?.configured||!Number.isSafeInteger(version)||version<1||!history)return null;
 const {from,untilExclusive,total,present,missing,job}=history;
 if(!validDay(from)||!validDay(untilExclusive)||from>=untilExclusive||
    ![total,present,missing].every(n=>Number.isSafeInteger(n)&&n>=0)||total===0||present+missing!==total)return null;
 const end=new Date(untilExclusive+'T00:00:00Z');
 end.setUTCDate(end.getUTCDate()-1);
 const max=end.toISOString().slice(0,10),phase=job?.state;
 const coverage=text`Doğrulanmış arşiv dönemi ${displayDay(from)} – ${displayDay(max)}.`;
 const counts=text`${total} siparişten ${present} tanesi Siparişler tablosunda mevcut. Eksik sipariş sayısı ${missing}.`;
 const outcome=Object.hasOwn(states,phase)?t(states[phase]):'';
 const canRequest=missing>0&&(!job||['verified','incomplete','failed'].includes(phase));
 return {version,from,max,canRequest,message:[coverage,counts,outcome].filter(Boolean).join(' ')};
}

export function mountPazaramaHistory(list,company,current,signal){
 list.insertAdjacentHTML('beforeend',html`<section data-history-panel hidden>
 <details hidden><summary>Pazarama geçmiş siparişleri</summary><form class="form-grid">
 <p class="settings-note" role="status"></p><label>Başlangıç tarihi<input name="historyFrom" type="date" required disabled></label>
 <small class="form-help">Seçilen tarihten arşiv döneminin sonuna kadar eksik Pazarama siparişleri kontrol edilir.</small>
 <div class="marketplace-setting-actions"><button type="submit" class="btn primary" disabled>Geçmiş siparişleri al</button>
 <button type="button" class="btn" data-history-refresh>Durumu yenile</button></div></form></details>
 <p class="form-error" role="alert" hidden></p><button type="button" class="text-btn" data-history-retry hidden>Pazarama arşiv durumunu yeniden kontrol et</button></section>`);
 const panel=list.lastElementChild,details=panel.querySelector('details'),form=details.querySelector('form');
 const date=form.elements.historyFrom,label=date.closest('label'),help=form.querySelector('small');
 const submit=form.querySelector('[type=submit]'),refresh=form.querySelector('[data-history-refresh]');
 const retry=panel.querySelector('[data-history-retry]'),status=form.querySelector('[role=status]'),error=panel.querySelector('[role=alert]');
 const path='/integrations/pazarama?company='+encodeURIComponent(company.id);
 const active=()=>!signal.aborted&&current()&&panel.isConnected;
 let state=null,busy=false;
 function controls(){
  date.disabled=busy||!state?.canRequest;
  submit.disabled=date.disabled||!date.value||!date.validity.valid;
  refresh.disabled=busy;retry.disabled=busy;
 }
 function message(value){
  error.hidden=!value;error.textContent=value||'';
  retry.hidden=!value||!details.hidden;
 }
 async function load(){
  if(busy||!active())return;
  busy=true;controls();message('');
  try{
   const data=await api(path,'GET',undefined,{signal});
   if(!active())return;
   state=pazaramaHistoryState(data);
   panel.hidden=details.hidden=!state;
   if(!state)return;
   date.min=state.from;date.max=state.max;
   if(!date.value||date.value<state.from||date.value>state.max)date.value=state.from;
   label.hidden=help.hidden=submit.hidden=!state.canRequest;
   status.textContent=state.message;
  }catch(e){
   if(active()){state=null;panel.hidden=false;message(e.message);}
  }finally{busy=false;if(active())controls();}
 }
 refresh.onclick=retry.onclick=()=>void load();
 date.oninput=controls;
 form.onsubmit=async event=>{
  event.preventDefault();
  if(!active()||busy||!state?.canRequest||!date.value||!date.validity.valid)return;
  const from=date.value,version=state.version;
  busy=true;controls();message('');
  try{
   const result=await api(path,'POST',{action:'extendHistory',from,version},{signal,confirmed:true});
   if(!active())return;
   state=null;label.hidden=help.hidden=submit.hidden=true;
   status.textContent=result.message||t('Geçmiş sipariş aktarımı sıraya alındı. Durumu yenileyebilirsiniz.');
  }catch(e){
   if(active()){
    state=null;message(e.status?e.message:t('İsteğin durumu doğrulanamadı. Durumu yenileyin.'));
   }
  }finally{busy=false;if(active())controls();}
 };
 void load();
}

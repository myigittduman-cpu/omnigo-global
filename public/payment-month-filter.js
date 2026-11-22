import {monthDateRange} from './filter-date-range.js';
import {shipmentMonthLabel} from './shipment-calendar.js';
import {html} from './i18n.js';
import {now,toast} from './ui.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';

const validMonth=value=>/^\d{4}-(0[1-9]|1[0-2])$/.test(value||'');

/** Keep payment periods separate from custom ranges on the other pages. */
export function paymentMonthRange(state,today=now()){
 const previous=state.paymentMonth,token=workspaceToken(state);
 if(!previous||previous.company!==token.company||previous.user!==token.user||previous.revision!==token.revision||!validMonth(previous.month)){
  state.paymentMonth={...token,month:today.slice(0,7),automatic:true};
 }else if(previous.automatic)previous.month=today.slice(0,7);
 state.month=state.paymentMonth.month;
 return {company:state.company,...monthDateRange(state.month+'-01'),automatic:state.paymentMonth.automatic};
}

export function paymentMonthFilter(state){
 paymentMonthRange(state);
 const selected=state.paymentMonth.month,current=Number(now().slice(0,4));
 const years=[...new Set([current-1,current,current+1,Number(selected.slice(0,4))])].sort((a,b)=>a-b);
 return html`<label class="shipment-city-filter shipment-month-filter payment-month-filter"><select name="month" data-payment-month-filter aria-label="Hakediş ayı" required>${years.map(year=>html`<optgroup label="${year}">${Array.from({length:12},(_,index)=>{const value=year+'-'+String(index+1).padStart(2,'0');return html`<option value="${value}" ${value===selected?'selected':''}>${shipmentMonthLabel(value,{year:false})}</option>`;}).join('')}</optgroup>`).join('')}</select></label>`;
}

export function bindPaymentMonthFilter(root,state,load){
 const select=root.querySelector('[data-payment-month-filter]');if(!select)return;
 select.addEventListener('change',async()=>{
  if(select.disabled||!validMonth(select.value)||state.view!=='payments')return;
  const token=workspaceToken(state),previous={...state.paymentMonth},previousMonth=state.month,previousDay=state.paymentDay,month=select.value;
  state.paymentMonth={...token,month,automatic:false};state.month=month;state.paymentDay='';
  select.disabled=true;select.setAttribute('aria-busy','true');
  try{await load(true);}
  catch(error){if(sameWorkspace(state,token)&&state.paymentMonth?.month===month){state.paymentMonth=previous;state.month=previousMonth;state.paymentDay=previousDay;if(select.isConnected)select.value=previous.month;toast(error.message);}}
  finally{if(select.isConnected){select.disabled=false;select.removeAttribute('aria-busy');}}
 });
}

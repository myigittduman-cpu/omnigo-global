import {defaultWorkspaceRange} from './filter-date-range.js';
import {html} from './i18n.js';
import {now,toast} from './ui.js';
import {paymentMonthRange,paymentMonthFilter,bindPaymentMonthFilter} from './payment-month-filter.js';
export const rangePages=Object.freeze(['overview','overviewSales','overviewTop','channels','payments','tasks','reports']);
export function workspaceRange(state){
 if(state.view==='payments')return paymentMonthRange(state);
 state.dateRange=defaultWorkspaceRange(state.dateRange,state.company,state.view,now());
 return state.dateRange;
}
export function dashboardQuery(state){
 const {from,to}=workspaceRange(state);
 return '/dashboard?'+new URLSearchParams({company:state.company,month:state.month,from,to});
}
export function workspaceDateRange(state){
 if(!rangePages.includes(state.view))return '';
 if(state.view==='payments')return paymentMonthFilter(state);
 const {from,to}=workspaceRange(state);
 return html`<form class="workspace-date-range" aria-label="Tarih aralığı"><label>Başlangıç tarihi<input type="date" name="from" value="${from}" required></label><label>Bitiş tarihi<input type="date" name="to" value="${to}" required></label><button type="submit" class="btn">Filtrele</button></form>`;
}
export function bindWorkspaceDateRange(root,state,load){
 if(state.view==='payments'){bindPaymentMonthFilter(root,state,load);return;}
 const form=root.querySelector('.workspace-date-range');if(!form)return;
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(!form.reportValidity())return;
  const from=form.elements.from.value,to=form.elements.to.value;
  if(from>to)return;
  const previous={...workspaceRange(state)},month=state.month,company=state.company;
  state.dateRange={company,from,to,automatic:false};state.month=to.slice(0,7);
  const button=form.querySelector('button');button.disabled=true;
  try{await load(true);}catch(error){if(state.company===company){state.dateRange=previous;state.month=month;toast(error.message);}}
  finally{if(button.isConnected)button.disabled=false;}
 });
}

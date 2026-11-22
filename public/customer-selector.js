import {html as i18nHtml} from './i18n.js';
import {workspaceCompany} from './workspace-company.js';
let selectionBinding;

export function customerSelector(state){
 if(state.user?.role!=='admin'||state.view!=='customers')return '';
 const company=state.companies.find(item=>Number(item.id)===Number(state.company));
 return i18nHtml`<div class="customer-selector customer-selector--compact">${workspaceCompany(company,{companies:state.companies,canSwitch:true,selectLabel:'Seçili mağaza'})}</div>`;
}

export function bindCustomerSelector(root,state,onChange){
 selectionBinding?.abort();selectionBinding=new AbortController();
 if(state.user?.role!=='admin')return;
 const selects=[...root.querySelectorAll('[data-workspace-company]')];
 let busy=false;
 selects.forEach(select=>select.addEventListener('change',async()=>{
  if(busy)return;busy=true;
  selects.forEach(control=>control.disabled=true);
  try{await onChange(Number(select.value));}
  finally{busy=false;selects.forEach(control=>{if(control.isConnected){control.value=String(state.company);control.disabled=false;}});}
 },{signal:selectionBinding.signal}));
}

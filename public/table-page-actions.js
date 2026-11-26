import {documentOutputActions} from './document-output-actions.js';
import {t} from './i18n.js';
import {customerCan} from './customer-access.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';
import {workspaceExportCompany} from './workspace-export-company.js';
import {topLevelPageTables} from './table-page-export-data.js';
import {toast} from './ui.js';

const mounted=new WeakMap();
const existing={
 analytics:{selector:'[data-export="xlsx"]',permission:'export',complete:true},
 shipments:{selector:'[data-shipment-export="xlsx"]',permission:'export',complete:true},
 deliveries:{selector:'[data-excel]',permission:'export',complete:true},
 cityProducts:{selector:'[data-products-output="excel"]',permission:'export',complete:true},
 financeProfitability:{selector:'[data-profit-output="pdf"]',missing:['excel']},
 payments:{selector:'[data-upcoming-download]',missing:['excel']},
 inbox:{selector:'[data-inbox="download"]',missing:['pdf','print','share']}
};

function allowed(state,view){
 if(!customerCan(state,view)||state.data?.workspaceAccess?.grants?.[view]?.export===false)return false;
 // Active payment documents already use the payment view grant.
 if(state.user?.role==='admin'||view==='payments')return true;
 return customerCan(state,view,'export');
}

const actionNames=['excel','pdf','print','share'];
function markup(names){
 return documentOutputActions(Object.fromEntries(names.map(name=>[name,{attr:'data-table-page-output',value:name}])));
}

function legacyButton(main,view){
 const selector=view==='fileCenter'?'[data-file-center-export]':existing[view]?.selector;
 const button=selector&&main.querySelector(selector);
 return button?.getClientRects().length?button:null;
}

export function bindTablePageActions(app,state){
 mounted.get(app)?.();
 const main=app.querySelector('main.main');
 if(!main)return;
 const view=state.view,token=workspaceToken(state),rule=existing[view];
 const current=()=>main.isConnected&&state.view===view&&sameWorkspace(state,token)&&allowed(state,view);
 if(!current()||rule?.complete)return;
 let scheduled=0,busy=false,toolbar=null,original=null,usingExisting=null;
 const add=names=>{
  const template=main.ownerDocument.createElement('template');template.innerHTML=markup(names);
  return template.content.firstElementChild;
 };
 function clear(){
  if(original&&toolbar?.contains(original))toolbar.before(original);
  toolbar?.remove();toolbar=null;original=null;
  usingExisting?.querySelectorAll('[data-table-page-output]').forEach(button=>button.remove());usingExisting=null;
 }
 function sync(){
  scheduled=0;
  if(!current()||!topLevelPageTables(main).length){clear();return;}
  const legacy=legacyButton(main,view);
  if(view==='fileCenter'&&legacy){clear();return;}
  // Payment documents keep their period selection and permitted operations.
  if(view==='payments'&&!legacy){clear();return;}
  const group=legacy?.closest('.document-output-actions');
  if(group&&rule?.missing.length===1){
   if(usingExisting===group&&group.querySelector('[data-table-page-output]'))return;
   clear();
   usingExisting=group;
   const actions=add(rule.missing);
   group.prepend(...Array.from(actions.children));
   return;
  }
  const invoiceControls=view==='shipmentInvoices'?main.querySelector('[data-tracking-search] .shipment-search-controls'):null;
  if(view==='shipmentInvoices'&&!invoiceControls){clear();return;}
  if(toolbar?.isConnected&&original===legacy&&(!invoiceControls||toolbar.parentElement===invoiceControls))return;
  clear();
  toolbar=add(legacy?rule.missing:actionNames);
  toolbar.classList.add('table-page-outputs');
  if(legacy){
   legacy.before(toolbar);toolbar.prepend(legacy);original=legacy;
  }else if(invoiceControls)invoiceControls.append(toolbar);
  else main.querySelector('.page-heading').after(toolbar);
 }
 function schedule(){
  if(!scheduled)scheduled=requestAnimationFrame(sync);
 }
 const observer=new MutationObserver(records=>{
  if(records.some(record=>record.type==='attributes'||[...record.addedNodes,...record.removedNodes].some(node=>
   node.nodeType===1&&(node.matches('table,thead,tbody,[data-inbox="download"],.document-output-actions')||node.querySelector('table,[data-inbox="download"],.document-output-actions')))))schedule();
 });
 observer.observe(main,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']});
 const handle=async event=>{
  const button=event.target.closest('[data-table-page-output]');
  if(!button||busy||!current())return;
  const company=workspaceExportCompany(state,token.company);
  if(!company){toast(t('Rapor firması belirlenemedi. Sayfayı yenileyin.'));return;}
  const title=main.querySelector('h1')?.innerText.trim()||t('Tablo');
  const tables=topLevelPageTables(main);
  const action=button.dataset.tablePageOutput;
  busy=true;button.disabled=true;
  try{
   const {runTablePageOutput}=await import('./table-page-output.js');
   if(current())await runTablePageOutput(action,{company,title,view,tables,current});
  }catch(error){if(current())toast(error.message);}
  finally{busy=false;button.disabled=false;}
 };
 main.addEventListener('click',handle);
 const dispose=()=>{
  observer.disconnect();if(scheduled)cancelAnimationFrame(scheduled);
  main.removeEventListener('click',handle);clear();
  mounted.delete(app);
 };
 mounted.set(app,dispose);schedule();
}

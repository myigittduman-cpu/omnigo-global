// Each control names its own list. A page can contain several independent tables or dialogs.
const targets=[
 ['button[data-orders-page]','[data-orders]','.orders-table-wrap'],
 ['button[data-inventory-page]','[data-inventory]','.inventory-table-wrap'],
 ['button[data-history-prev],button[data-history-next]','.inventory-history-dialog .dialog-body','.inventory-history-table-wrap'],
 ['button[data-inbox-page]','[data-marketplace-questions]','.inbox-list-scroll'],
 ['button[data-returns-page]','[data-returns]','.return-table-wrap'],
 ['button[data-shipment-page]','[data-shipments]','.shipment-scroll'],
 ['button[data-tracking-page]','.shipment-document-tracking','.shipment-tracking-scroll'],
 ['.delivery-pages button[data-page]','[data-delivery]','.delivery-orders'],
 ['button[data-additions-page]','.delivery-additions','.delivery-additions-scroll'],
 ['button[data-products-page]','[data-city-products]','.city-products-scroll'],
 ['button[data-file-center-page]','[data-file-center]','.file-center-table'],
 ['button[data-finance-index],button[data-finance-page]','.finance-report','[data-finance-table]'],
 ['button[data-product-page]','.sales-analysis-dialog .dialog-body','.sales-products .table-scroll'],
 ['button[data-product-page]','[data-reporting="analytics"]','[data-report-products] .report-table-scroll'],
 ['button[data-attention-page]','[data-attention-center]','[data-attention-result] .table-scroll'],
 ['button[data-upcoming-page]','[data-reporting="payments"]','.upcoming-payments-table-frame'],
 ['button[data-received-page]','.received-payments-dialog .dialog-body','.received-payments-scroll'],
 ['button[data-day-payment-page]','.payment-day-dialog .dialog-body','.payment-day-table-wrap'],
 ['button[data-activity-prev],button[data-activity-next]','[data-settings-activity]','.settings-activity-scroll'],
 ['button[data-directory-goto]','[data-account-directory]','[data-directory-list] .account-directory-scroll'],
 // Support paging re-renders main. The shell survives only within the same role and store.
 ['button[data-support="next"],button[data-support="prev"]','.workspace','[data-support-content] .support-grid'],
];

const commonControls='[data-page-control],.pagination-numbers > button,:is(nav,footer,div):has(> .pagination-numbers) > button';
export const pageControlSelector=[...new Set(targets.map(([selector])=>selector)),commonControls].join(',');

function target(scope,key,resolve){
 const frame=resolve();
 return frame?{scope,key,frame,resolve}:null;
}

function declaredTarget(button){
 const scope=button.closest('[data-page-scope]');
 if(!scope)return null;
 const name=button.getAttribute('data-page-control'),controlsId=button.getAttribute('aria-controls');
 const resolve=()=>{
  const frames=Array.from(scope.querySelectorAll('[data-page-frame]')).filter(frame=>frame.closest('[data-page-scope]')===scope);
  if(controlsId)return frames.find(frame=>frame.id===controlsId)||null;
  if(name)return frames.find(frame=>frame.getAttribute('data-page-frame')===name)||null;
  return frames.length===1?frames[0]:null;
 };
 return target(scope,'declared:'+(controlsId||name||''),resolve);
}

// A new table using shared pagination buttons may omit the explicit frame when its scope
// contains exactly one table. Never select the first table in a multi-table report.
function commonTarget(button){
 const scope=button.closest('[data-page-scope],section,[role="tabpanel"],.dialog-body');
 if(!scope)return null;
 const resolve=()=>{
  const tables=scope.querySelectorAll('table');
  if(tables.length!==1)return null;
  const frame=tables[0].parentElement;
  if(frame===scope||frame.contains(button.closest('nav')||button))return null;
  return frame;
 };
 return target(scope,'shared-table',resolve);
}

export function pageTableForControl(control){
 const button=control?.closest?.(pageControlSelector);
 if(!button)return null;
 for(const [selector,scopeSelector,frameSelector] of targets){
  if(!button.matches(selector))continue;
  const scope=button.closest(scopeSelector);
  if(scope)return target(scope,frameSelector,()=>scope.querySelector(frameSelector));
 }
 if(!button.matches(commonControls))return null;
 if(button.closest('[data-page-scope]')?.querySelector('[data-page-frame]'))return declaredTarget(button);
 return commonTarget(button);
}

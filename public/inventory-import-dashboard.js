import {api} from './api.js';
import {openDialog} from './ui.js';
import {sameWorkspace} from './workspace-context.js';
import {inventoryRefreshDelay} from './inventory-refresh-policy.js';
import {inventoryImportDashboardButton,inventoryImportDashboardView,inventoryImportRuns} from './inventory-import-dashboard-view.js';
import {isLiveImportSnapshot} from './marketplace-import-report.js';
import {contentError} from './content-state.js';
import {revealContent} from './motion.js';

// Load final dialog geometry before its first visible frame, including a cold entry.
let stylesReady;
function ensureStyles(){
 if(!stylesReady)stylesReady=new Promise(resolve=>{const style=document.createElement('link');style.rel='stylesheet';style.href=new URL('inventory-import-dashboard.css',import.meta.url).href;style.onload=()=>resolve(true);style.onerror=()=>{stylesReady=null;resolve(false);};document.head.append(style);});
 return stylesReady;
}
if(typeof document!=='undefined')void ensureStyles();

/** Mount into the real shared page heading; never add a row to the stock controls. */
const headingMounts=new WeakMap();
export function mountInventoryImportHeading(root,{admin,onOpen}){
 const heading=root.closest('.main')?.querySelector(':scope > .page-heading'),actions=heading?.querySelector('.page-heading-actions');
 if(!admin||!heading||!actions)return {paint(){},dispose(){}};
 headingMounts.get(heading)?.dispose();
 const host=document.createElement('div');host.dataset.inventoryImportActions='';heading.classList.add('inventory-page-heading');actions.append(host);let markup='',disposed=false;
 const click=event=>{if(event.target.closest('[data-inventory-import-dashboard]'))onOpen();};host.addEventListener('click',click);
 const controller={paint(data){if(disposed)return;const next=inventoryImportDashboardButton(data,admin);if(markup===next)return;const focused=host.contains(document.activeElement);host.innerHTML=next;markup=next;if(focused)host.querySelector('button')?.focus({preventScroll:true});},dispose(){if(disposed)return;disposed=true;host.removeEventListener('click',click);host.remove();if(headingMounts.get(heading)===controller){headingMounts.delete(heading);heading.classList.remove('inventory-page-heading');}}};
 headingMounts.set(heading,controller);return controller;
}

/** Keep unchanged reports, focus, expanded rows and the dialog's scroll lane in place. */
export function paintInventoryImportDashboard(body,markup){
 if(body.inventoryImportMarkup===markup)return;
 const scroll=body.scrollTop,focused=body.contains(document.activeElement)?document.activeElement:null;
 const keyFor=node=>node?.getAttribute('data-inventory-import-report')??(['data-inventory-import-back','data-inventory-import-refresh'].find(key=>node?.hasAttribute(key))||null);
 const focusKey=keyFor(focused),details=[...body.querySelectorAll('details')].map(node=>node.open),scrollNodes=[...body.querySelectorAll('*')].map((node,index)=>({index,top:node.scrollTop,left:node.scrollLeft})).filter(node=>node.top||node.left);
 body.innerHTML=markup;body.inventoryImportMarkup=markup;
 [...body.querySelectorAll('details')].forEach((node,index)=>{if(index<details.length)node.open=details[index];});
 const nodes=body.querySelectorAll('*');for(const saved of scrollNodes){const node=nodes[saved.index];if(node){node.scrollTop=saved.top;node.scrollLeft=saved.left;}}
 if(focusKey!=null)[...body.querySelectorAll('button')].find(node=>keyFor(node)===focusKey)?.focus({preventScroll:true});
 body.scrollTop=scroll;
}

export async function openInventoryImportDashboard({state,token,current,getData,read,onData=()=>{}}){
 if(state.user?.role!=='admin'||!current()||!sameWorkspace(state,token))return;
 const styled=await ensureStyles();if(!current()||!sameWorkspace(state,token))return;
 if(!styled){openDialog('Aktarım raporları',contentError('Rapor görünümü yüklenemedi. Lütfen yeniden deneyin.'),{className:'notification-detail-dialog'});return;}
 const dialog=openDialog('Aktarım raporları','',{className:'notification-detail-dialog marketplace-import-dialog inventory-import-dashboard-dialog'}),body=dialog.querySelector('.dialog-body'),abort=new AbortController();
 let data=getData(),selectedId=null,detail=null,detailRequest=0,error='',timer,busy=false,disposed=false,listScroll=0;
 const active=()=>!disposed&&dialog.open&&dialog.classList.contains('inventory-import-dashboard-dialog')&&state.user?.role==='admin'&&current()&&sameWorkspace(state,token);
 const paint=()=>{if(active())paintInventoryImportDashboard(body,inventoryImportDashboardView(data,{selectedId,detail,error}));};
 const dispose=()=>{disposed=true;clearTimeout(timer);abort.abort();dialog.removeEventListener('close',onClose);body.removeEventListener('click',onClick);};
 const onClose=()=>{if(!dialog.open){dispose();if(current())onData(data);}};
 const schedule=()=>{clearTimeout(timer);if(active())timer=setTimeout(()=>void refresh(),inventoryRefreshDelay(data));};
 async function refresh(){
  if(!active()){dispose();return;}if(busy)return;busy=true;
  try{const result=await read(abort.signal);if(!active())return;data=result;error='';if(selectedId&&!inventoryImportRuns(data).some(run=>String(run.runId)===selectedId)){selectedId=null;detail=null;}paint();}
  catch(cause){if(active()&&cause.name!=='AbortError'){error='Aktarım raporları yenilenemedi. Son alınan bilgiler gösteriliyor.';paint();}}
  finally{busy=false;schedule();}
 }
 async function showReport(id){
  const run=inventoryImportRuns(data).find(item=>String(item.runId)===id);if(!run)return;
  if(selectedId==null)listScroll=body.scrollTop;selectedId=id;detail=null;error='';const request=++detailRequest;paint();body.scrollTop=0;revealContent(body.querySelector('[data-inventory-import-dashboard-content]'));
  const noticeId=Number(run.noticeId);if(isLiveImportSnapshot(run)||!['complete','failed'].includes(run.state)||!Number.isSafeInteger(noticeId)||noticeId<=0)return;
  // Preserve the existing completed-report notification detail/read flow in this frame.
  try{const result=await api(`/notifications/operation/${noticeId}?company=${token.company}`,'GET',undefined,{signal:abort.signal});if(!active()||request!==detailRequest||selectedId!==id)return;detail=result;paint();await api(`/notifications/operation/${noticeId}/read?company=${token.company}`,'POST',{}, {signal:abort.signal});}
  catch(cause){if(active()&&request===detailRequest&&selectedId===id&&cause.name!=='AbortError'){error='Bildirim ayrıntıları yenilenemedi. Kayıtlı aktarım raporu gösteriliyor.';paint();}}
 }
 function onClick(event){
  const button=event.target.closest('button');if(!button||!active())return;
  if(button.hasAttribute('data-inventory-import-report')){void showReport(button.dataset.inventoryImportReport);return;}
  if(button.hasAttribute('data-inventory-import-back')){const previousId=selectedId;selectedId=null;detail=null;detailRequest++;error='';paint();[...body.querySelectorAll('[data-inventory-import-report]')].find(node=>node.dataset.inventoryImportReport===previousId)?.focus({preventScroll:true});body.scrollTop=listScroll;revealContent(body.querySelector('[data-inventory-import-dashboard-content]'));return;}
  if(button.hasAttribute('data-inventory-import-refresh'))void refresh();
 }
 dialog.addEventListener('close',onClose);body.addEventListener('click',onClick);paint();schedule();
 return {dialog,dispose};
}

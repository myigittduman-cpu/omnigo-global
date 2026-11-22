import {api} from './api.js';
import {contentError,loadingState} from './content-state.js';
import {paintContent} from './content-paint.js';
import {currentLocale,html,t} from './i18n.js';
import {markPageUpdated} from './page-refresh.js';
import {esc,icon} from './ui.js';
import {pendingWorkTargets} from './customer-access.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';
import {workspaceIcon} from './workspace-icons.js';
import {pageSnapshot} from './workspace-page-cache.js';

const tasks=[
 {key:'invoice',page:'shipmentInvoices',label:'Bekleyen faturalar',symbol:()=>icon('reports')},
 {key:'dispatch',page:'deliveries',label:'Sevk bekleyen siparişler',symbol:()=>workspaceIcon('truck')},
];
const countLabel=count=>count.toLocaleString(currentLocale());
const validCount=count=>Number.isSafeInteger(count)&&count>=0;
const workTasks=(state)=>{const targets=pendingWorkTargets(state);return tasks.filter(task=>targets.includes(task.page));};
const badge=(count,description='')=>html`<span class="customer-work-count">${validCount(count)?countLabel(count):'—'}${description?html`<span class="customer-work-count-description"> ${esc(description)}</span>`:''}</span>`;

const snapshotFor=state=>pageSnapshot(state,'overviewWork',['counts']);
export function customerWorkContent(state,{inTab=false}={}){
 const rows=workTasks(state);
 if(!rows.length&&!inTab&&state.view!=='overviewWork')return '';
 const model={counts:null};
 snapshotFor(state).restore(model);
 const visible=visibleTasks(rows,model.counts);
 return html`<section class="customer-work" data-customer-work aria-labelledby="customer-work-title" ${!inTab&&state.view==='overview'&&model.counts&&!visible.length?'hidden':''}>
  <header><h2 id="customer-work-title">İşlem bekleyenler</h2><output data-customer-work-total aria-live="polite">${totalBadge(visible,model.counts)}</output></header>
  <div data-customer-work-result ${model.counts||!rows.length?'':'aria-busy="true"'}>${workResult(visible,model.counts)}</div>
 </section>`;
}

function visibleTasks(rows,counts){return counts?rows.filter(({key})=>validCount(counts[key])):rows;}
function totalBadge(rows,counts){
 const sum=counts&&rows.length?rows.reduce((n,{key})=>n+counts[key],0):null;
 return badge(sum,t('bekleyen işlem'));
}
function workResult(rows,counts){
 if(!rows.length)return html`<p class="customer-work-empty" role="status">Gösterilecek bekleyen işlem yok.</p>`;
 return workRows(rows,counts)+(counts?'':html`<div class="customer-work-loading">${loadingState('Bekleyen işlemler yükleniyor…',{compact:true})}</div>`);
}
function workRows(rows,counts){
 return html`<ul class="customer-work-list">${rows.map(({key,page,label,symbol})=>{
  const count=counts?.[key];
  return html`<li><button type="button" class="customer-work-row" data-customer-work-target="${page}" ${validCount(count)?'':'disabled'}>
   ${symbol()}<span class="customer-work-label">${t(label)}</span>
   ${badge(count)}${icon('arrow')}
  </button></li>`;
 }).join('')}</ul>`;
}

export function bindCustomerWork(app,state,{navigate}={}){
 const root=app.querySelector('[data-customer-work]');if(!root)return;
 const view=state.view,token=workspaceToken(state),tab=root.closest('[role=tabpanel]');
 const result=root.querySelector('[data-customer-work-result]'),total=root.querySelector('[data-customer-work-total]');
 const current=()=>root.isConnected&&state.view===view&&sameWorkspace(state,token);
 const model={counts:null},snapshot=snapshotFor(state);snapshot.restore(model);
 let inFlight=null;
 function paint(value){
  const rows=visibleTasks(workTasks(state),value);
  const focus=document.activeElement?.dataset?.customerWorkTarget;
  model.counts=value;snapshot.save(model);
  paintContent(result,workResult(rows,value));
  paintContent(total,totalBadge(rows,value));
  if(focus)result.querySelector(`[data-customer-work-target="${focus}"]`)?.focus({preventScroll:true});
  root.hidden=!tab&&view==='overview'&&!rows.length;
 }
 async function load(){
  try{
   const data=await api('/work/pending-counts?'+new URLSearchParams({company:token.company}));
   if(!current())return;
   paint(data.counts);markPageUpdated();
  }catch(error){
   if(current()&&[401,403].includes(error.status)){
    paint({});
   }else if(current()&&!model.counts){
    paintContent(result,html`<div class="customer-work-error">${contentError(error.message)}<button type="button" class="btn" data-customer-work-retry>Tekrar dene</button></div>`);
   }
   throw error;
  }finally{if(current())result.removeAttribute('aria-busy');}
 }
 function refresh(manual=false){
  if(!current()||tab?.hidden)return Promise.resolve();
  const request=inFlight??=load().finally(()=>{inFlight=null;});
  return manual?request:request.catch(()=>{});
 }
 root.workRefresh=()=>refresh();
 if(tab||view==='overviewWork')root.pageRefresh=()=>refresh(true);
 root.addEventListener('click',event=>{
  const button=event.target.closest('button');
  if(!button||button.disabled||!current())return;
  if(button.hasAttribute('data-customer-work-retry')){void refresh();return;}
  const page=button.dataset.customerWorkTarget;
  if(page&&pendingWorkTargets(state).includes(page)){
   const position=page==='deliveries'?{city:'all',queue:'all',page:0}:{queue:'pending',page:0};
   void navigate?.(page,{position});
  }
 });
 void refresh();
}

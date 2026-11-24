import {api} from './api.js';
import {paintContent} from './content-paint.js';
import {monthDateRange} from './filter-date-range.js';
import {financeReportPages} from './finance-report-pages.js';
import {financeReportResult} from './finance-report-view.js';
import {html,t} from './i18n.js';
import {latestPageRead} from './latest-page-read.js';
import {syncManualDateFilters} from './manual-date-filters.js';
import {markPageUpdated} from './page-refresh.js';
import {openProfitabilityRates} from './profitability-rates.js';
import {exportProfitabilityDocument} from './profitability-document.js';
import {scrollTableStart} from './table-page-scroll.js';
import {alignTableDisclosureLabels} from './table-disclosure-alignment.js';
import {hasTableTextSelection} from './table-text-selection.js';
import {esc,now,toast} from './ui.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';
import {workspaceExportCompany} from './workspace-export-company.js';
import {clearMenuSnapshot,pageSnapshot} from './workspace-page-cache.js';

function initialFilters() {
  return {...monthDateRange(now(),{throughToday:true}),channel:'all',orderNumber:''};
}

const isRefundReport = view => view === 'financeRefunds';
const focusKeys=['data-finance-coverage','data-finance-table','data-finance-size','data-finance-index','data-finance-page'];
const reportCompanyName=state=>state.companies?.find(company=>Number(company.id)===Number(state.company))?.name||'';

function focusedControl(result) {
  const element=result.ownerDocument.activeElement;
  if(!result.contains(element))return '';
  for(const key of focusKeys){
    const value=element.getAttribute(key);
    if(value!==null)return `[${key}="${value}"]`;
  }
  return '';
}

export function financeReportContent(state) {
  if(state.user?.role!=='admin'||!state.company)return '';
  const view=state.view,report=financeReportPages[view],filters=initialFilters();
  const pageSize=report.pageSize??20;
  return html`<section class="reporting finance-report" data-reporting="${view}" aria-label="${esc(t(report.label))}">
    <form class="report-filter finance-report-filter">
      <label>Başlangıç tarihi<input type="date" name="from" value="${filters.from}" required></label>
      <label>Bitiş tarihi<input type="date" name="to" value="${filters.to}" required></label>
      <label>Pazaryeri<select name="channel"><option value="all">Tüm pazaryerleri</option></select></label>
      ${isRefundReport(view)?html`<label>Sipariş numarası<input name="orderNumber" type="search" maxlength="180" autocomplete="off"></label>`:''}
      <button type="submit" class="btn primary">Filtrele</button>
      ${view==='financeProfitability'?html`<button type="button" class="btn" data-finance-rates disabled>Komisyon oranları</button>`:''}
    </form>
    <div data-finance-result>${financeReportResult(view,null,{filters,page:1,pageSize},reportCompanyName(state))}</div>
  </section>`;
}

export function bindProfitDetails(result){
  const document=result.ownerDocument;
  result.addEventListener('beforetoggle',event=>{
    if(event.target.matches('.profit-detail-panel'))delete event.target.dataset.positioned;
  },true);
  result.addEventListener('toggle',event=>{
    const panel=event.target;
    if(event.newState!=='open'||!panel.matches('.profit-detail-panel'))return;
    const trigger=result.querySelector('[popovertarget="'+panel.id+'"]');
    if(!trigger)return;
    const anchor=trigger.getBoundingClientRect(),edge=8,gap=6;
    const width=document.documentElement.clientWidth,height=document.documentElement.clientHeight;
    const left=Math.max(edge,Math.min(anchor.right-panel.offsetWidth,width-panel.offsetWidth-edge));
    const below=anchor.bottom+gap;
    const top=below+panel.offsetHeight<=height-edge?below:Math.max(edge,anchor.top-panel.offsetHeight-gap);
    panel.style.left=left+'px';panel.style.top=top+'px';
    panel.dataset.positioned='';
    const stop=new AbortController();
    const dismiss=e=>{if((e.type==='resize'||!panel.contains(e.target))&&panel.matches(':popover-open'))panel.hidePopover();};
    document.addEventListener('scroll',dismiss,{capture:true,passive:true,signal:stop.signal});
    document.defaultView.addEventListener('resize',dismiss,{signal:stop.signal});
    panel.addEventListener('toggle',()=>stop.abort(),{once:true});
  },true);
}

export function bindFinanceReport(root,state) {
  if(state.user?.role!=='admin'||!state.company||root.pageRefresh)return;
  const token=workspaceToken(state),view=root.dataset.reporting;
  const form=root.querySelector('form'),result=root.querySelector('[data-finance-result]');
  const valid=()=>root.isConnected&&state.user?.role==='admin'&&state.view===view&&sameWorkspace(state,token);
  const snapshot=pageSnapshot(state,view,['filters','page','pageSize','report']);
  const model={filters:initialFilters(),page:1,pageSize:financeReportPages[view].pageSize??20,report:null,loading:false};
  snapshot.restore(model);
  const requests=latestPageRead(model,valid);
  const channelSelect=form.elements.channel;
  const ratesButton=form.querySelector('[data-finance-rates]');
  const companyName=reportCompanyName(state);
  const document=root.ownerDocument;
  if(view==='financeProfitability')bindProfitDetails(result);
  let retry,filterRevision=0,outputBusy=false;

  function displayChannels(selected=channelSelect.value) {
    const incoming=model.report?.marketplaces??model.report?.channels??[];
    const channelNames=new Set(incoming.filter(name=>typeof name==='string'&&name!=='all'));
    if(model.filters.channel!=='all')channelNames.add(model.filters.channel);
    const options=[...channelNames].sort((left,right)=>left.localeCompare(right,'tr'));
    if(ratesButton)ratesButton.disabled=!options.length;
    if(channelSelect.options.length===options.length+1&&options.every((name,index)=>channelSelect.options[index+1].value===name))return;
    const elements=options.map(name=>{
      const element=document.createElement('option');
      element.value=name;
      element.textContent=name;
      return element;
    });
    channelSelect.replaceChildren(channelSelect.options[0],...elements);
    channelSelect.value=channelNames.has(selected)?selected:'all';
  }

  function display(error='') {
    const focus=focusedControl(result);
    if(!paintContent(result,financeReportResult(view,model.report,model,companyName,error)))return;
    if(view==='financeProfitability')alignTableDisclosureLabels(result.querySelector('[data-finance-table]')??result,'.profit-amount-line');
    if(focus)(result.querySelector(focus)||result.querySelector('[aria-current="page"]'))?.focus({preventScroll:true});
  }

  displayChannels();
  for(const name of ['from','to','channel','orderNumber']){
    if(form.elements[name])form.elements[name].value=model.filters[name];
  }
  syncManualDateFilters(form);

  async function refresh({filters=model.filters,page=model.page,pageSize=model.pageSize,passive=false,propagate=false,focus=''}={}) {
    const blocked=()=>passive&&(document.querySelector('dialog[open]')||result.querySelector('.profit-detail-panel:popover-open')||hasTableTextSelection(result)||root.contains(document.activeElement)&&document.activeElement.matches('select'));
    if(!valid()||passive&&(model.loading||blocked()))return;
    const request=requests.begin();
    const {from,to,channel,orderNumber}=filters;
    const query=new URLSearchParams({company:token.company,from,to,channel,page,pageSize});
    if(isRefundReport(view)&&orderNumber)query.set('orderNumber',orderNumber);
    result.setAttribute('aria-busy','true');
    try{
      const report=await api(financeReportPages[view].endpoint+'?'+query,'GET',null,{signal:request.signal});
      if(!request.active()||blocked())return;
      Object.assign(model,{filters,page:report.page,pageSize:report.pageSize,report});
      retry=undefined;
      displayChannels();
      display();
      snapshot.save(model);
      markPageUpdated();
      if(focus){
        result.querySelector(focus)?.focus({preventScroll:true});
        scrollTableStart(result,'[data-finance-table]');
      }
    }catch(error){
      if(!request.active())return;
      if(!model.report||[401,403,404].includes(error.status)){
        model.report=null;
        clearMenuSnapshot(state,view);
        retry={filters,page,pageSize,focus};
        display(error.message);
      }else if(!passive&&!propagate){
        const sizeControl=result.querySelector('[data-finance-size]');
        if(sizeControl)sizeControl.value=String(model.pageSize);
        toast(error.message);
      }
      if(propagate)throw error;
    }finally{
      if(request.finish())result.removeAttribute('aria-busy');
    }
  }

  form.addEventListener('submit',event=>{
    event.preventDefault();
    if(!form.reportValidity())return;
    const filters=Object.fromEntries(new FormData(form));
    if(!filters.from||!filters.to||filters.from>filters.to)return;
    filterRevision++;
    void refresh({filters:{...filters,orderNumber:(filters.orderNumber||'').trim()},page:1});
  });
  ratesButton?.addEventListener('click',()=>{
    const channels=[...channelSelect.options].map(option=>option.value).filter(value=>value!=='all');
    void openProfitabilityRates({company:token.company,channels,channel:channelSelect.value,valid,refresh});
  });
  result.addEventListener('click',async event=>{
    const button=event.target.closest('button');
    if(!button||button.disabled)return;
    if(button.hasAttribute('data-profit-output')&&valid()&&view==='financeProfitability'){
      if(outputBusy||!model.report?.total)return;
      const company=workspaceExportCompany(state,token.company);
      if(Number(company?.id)!==Number(token.company))return;
      const {from,to,channel}=model.report,revision=filterRevision;
      const current=()=>valid()&&filterRevision===revision&&model.report?.from===from&&model.report?.to===to&&model.report?.channel===channel;
      outputBusy=true;
      try{
        const message=await exportProfitabilityDocument(button.dataset.profitOutput,{company,filters:{from,to,channel},current});
        if(message&&current())toast(message);
      }catch(error){
        if(current())toast(error.message);
      }finally{outputBusy=false;}
    }else if(button.hasAttribute('data-finance-retry')){
      void refresh(retry);
    }else if(button.hasAttribute('data-finance-page')||button.hasAttribute('data-finance-index')){
      const page=button.hasAttribute('data-finance-index')?Number(button.dataset.financeIndex)+1:Number(button.dataset.financePage);
      if(page!==model.page||model.loading)void refresh({page,focus:'[aria-current="page"]'});
    }
  });
  result.addEventListener('change',event=>{
    if(!event.target.matches('[data-finance-size]'))return;
    const pageSize=Number(event.target.value);
    event.target.value=String(model.pageSize);
    void refresh({page:1,pageSize,focus:'[data-finance-size]'});
  });
  root.reportRefresh=()=>refresh({...retry,passive:true});
  root.pageRefresh=()=>refresh({...retry,propagate:true});
  if(model.report)display();
  void refresh();
}

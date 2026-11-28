import {turnoverMoney,rawTurnoverMoney,salesTurnoverVisible,customerTurnover} from './sales-turnover.js';
import {html,t} from './i18n.js';
import {esc,money} from './ui.js';
import {api} from './api.js';
import {workspaceRange} from './workspace-date-range.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {rankBestsellers} from './report-bestsellers.js';
import {productImage,bindProductImages} from './top-products.js';
import {beginContentLoad,contentError} from './content-state.js';
import {attentionShell,bindAttentionCenter} from './attention-center.js';
import {customerWorkContent} from './customer-work.js';
import {customerPageVisible} from './customer-access.js';
import {customerOverviewMenu} from './customer-overview-menu.js';
import {usesCustomerOverviewTabs} from './customer-overview-layout.js';

export function overviewSectionOptions(state){
 const sections=usesCustomerOverviewTabs(state)
  ?{summary:'Özet',...Object.fromEntries(Object.entries(customerOverviewMenu).filter(([page])=>customerPageVisible(state,page)).map(([,entry])=>[entry.section,entry.label]))}
  :{summary:'Özet',sales:'Pazaryeri Satışları',top:'En Çok Satan 10',...(state.user?.role==='admin'?{attention:'İlgilenilmesi gerekenler',work:'İşlem Bekleyenler'}:{})};
 const allowed=state.data?.workspaceAccess?.overviewSections;
 return Array.isArray(allowed)?Object.fromEntries(Object.entries(sections).filter(([key])=>allowed.includes(key))):sections;
}
export function overviewSections(state,{summary,sales,header=''}){
 const sections=overviewSectionOptions(state);
 const selected=state.overviewSections?.company===state.company?state.overviewSections.tab:'summary';
 const active=Object.hasOwn(sections,selected)?selected:Object.keys(sections)[0];
 const tabs=Object.entries(sections).map(([key,label])=>html`<button class="btn" type="button" role="tab" id="overview-tab-${key}" data-overview-tab="${key}" aria-selected="${active===key}" aria-controls="overview-panel-${key}" tabindex="${active===key?0:-1}">${t(label)}${key==='attention'?'<span class="attention-count" data-attention-count hidden></span>':''}</button>`).join('');
 const contents={summary:()=>summary,sales:()=>sales,top:()=>topShell(state),attention:()=>attentionShell(state),work:()=>customerWorkContent(state,{inTab:true})};
 const panels=Object.keys(sections).map(key=>html`<div id="overview-panel-${key}" role="tabpanel" aria-labelledby="overview-tab-${key}" ${key===active?'':'hidden'}>${contents[key]()}</div>`).join('');
 return html`<section class="overview-sections" data-overview-sections>${header}<nav class="overview-section-tabs ${state.user?.role==='admin'?'overview-section-tabs--admin':usesCustomerOverviewTabs(state)?'overview-section-tabs--customer':''}" role="tablist" aria-label="Genel bakış bölümleri">${tabs}</nav>${panels}</section>`;
}
function topShell(state){
 return html`<section class="panel overview-top-products"><div class="panel-heading"><h2>En Çok Satan 10</h2><label class="overview-top-filter">Pazaryeri<select data-overview-top-channel><option value="all">Tüm pazaryerleri</option>${state.data.channels.filter(c=>c.status==='active').map(c=>html`<option value="${esc(c.name)}">${esc(c.name)}</option>`).join('')}</select></label></div><p class="panel-note">${t(state.data.turnoverDisplayBasis==='after_cancellations'?'Üstte seçtiğiniz tarih aralığı · İptal sonrası satış adedine göre ilk 10 ürün':'Üstte seçtiğiniz tarih aralığı · Net satış adedine göre ilk 10 ürün')}</p><div data-overview-top-result></div></section>`;
}
export function overviewTopContent(state){
 return html`<section class="overview-sections" data-overview-sections>${topShell(state)}</section>`;
}
export function overviewTopResult(data){
 const rows=rankBestsellers(data.products),amounts=salesTurnoverVisible(data);
 const adjusted=data.turnoverDisplayBasis==='after_cancellations',raw=customerTurnover(data),after=amounts&&raw&&!adjusted&&data.sensitiveVisibility?.afterCancellationTurnover===true;
 return (data.notes||[]).map(note=>html`<p class="sales-warning">${esc(note)}</p>`).join('')+(rows.length?html`<div class="table-scroll"><table class="overview-top-table"><thead><tr><th>Sıra</th><th>Ürün / stok kodu</th><th>Pazaryerleri</th><th>${t(raw?'Satış adedi':'Net satış adedi')}</th>${amounts?html`<th>${t(adjusted?'İptal sonrası ciro':'Satış tutarı')}</th>`:''}${after?html`<th>İptaller Sonrası Ciro</th>`:''}</tr></thead><tbody>${rows.map(p=>html`<tr><td>${p.rank}</td><td><div class="sales-product-name">${productImage(p)}<div><strong>${esc(p.title)}</strong><small>${esc(p.sku)}</small></div></div></td><td>${esc(p.channels.join(', '))}</td><td>${p.netQuantity}</td>${amounts?html`<td>${raw?rawTurnoverMoney(p):turnoverMoney(p)}</td>`:''}${after?html`<td>${p.salesTurnoverCents===null?t('Bekleniyor'):money(p.salesTurnoverCents)}</td>`:''}</tr>`).join('')}</tbody></table></div>`:html`<p class="sales-blank">Seçili aralıkta satış adedi doğrulanmış ürün bulunmuyor.</p>`);
}
export function bindOverviewSections(root,state,options={}){
 bindAttentionCenter(root,state,options);
 const sections=overviewSectionOptions(state);
 const workspace=root.querySelector('[data-overview-sections]');if(!workspace)return;
 const token=workspaceToken(state),view=state.view,result=workspace.querySelector('[data-overview-top-result]'),filter=workspace.querySelector('[data-overview-top-channel]');
 if(filter&&state.overviewSections?.company===state.company&&[...filter.options].some(o=>o.value===state.overviewSections.channel))filter.value=state.overviewSections.channel;
 let request=0,loaded=false;
 const valid=()=>workspace.isConnected&&sameWorkspace(state,token)&&state.view===view;
 async function load(){
  if(!valid()||!result||!filter)return;const sequence=++request,{from,to}=workspaceRange(state),complete=beginContentLoad(result,'En çok satanlar hazırlanıyor…');
  try{const data=await api('/sales/top-products?'+new URLSearchParams({company:token.company,from,to,channel:filter.value}));if(!valid()||sequence!==request)return;result.innerHTML=overviewTopResult({...data,workspaceAccess:state.data.workspaceAccess});bindProductImages(result);loaded=true;}
  catch(error){if(valid()&&sequence===request)result.innerHTML=contentError(error.message);}
  finally{complete();}
 }
 function select(key){
  if(!Object.hasOwn(sections,key)||!valid())return;
  state.overviewSections={company:state.company,tab:key,channel:filter?.value||'all'};
  if(!['attention','work'].includes(key)&&workspace.dataset.refreshPending==='true'&&options.render){options.render();return;}
  workspace.querySelectorAll('[data-overview-tab]').forEach(b=>{b.setAttribute('aria-selected',String(b.dataset.overviewTab===key));b.tabIndex=b.dataset.overviewTab===key?0:-1;});
  for(const name of Object.keys(sections))workspace.querySelector('#overview-panel-'+name).hidden=name!==key;
  if(key==='top'&&!loaded)void load();
  if(key==='attention')void workspace.querySelector('[data-attention-center]')?.attentionRefresh?.();
  if(key==='work')void workspace.querySelector('[data-customer-work]')?.workRefresh?.();
 }
 workspace.querySelectorAll('[data-overview-tab]').forEach(button=>{
  button.onclick=()=>select(button.dataset.overviewTab);
  button.onkeydown=event=>{const keys=Object.keys(sections),at=keys.indexOf(button.dataset.overviewTab),next=event.key==='ArrowRight'?keys[(at+1)%keys.length]:event.key==='ArrowLeft'?keys[(at+keys.length-1)%keys.length]:event.key==='Home'?keys[0]:event.key==='End'?keys.at(-1):null;if(next){event.preventDefault();select(next);workspace.querySelector('[data-overview-tab="'+next+'"]').focus({preventScroll:true});}};
 });
 if(filter)filter.onchange=()=>{state.overviewSections={company:state.company,tab:'top',channel:filter.value};void load();};
 if(view==='overviewTop'||(state.overviewSections?.company===state.company&&state.overviewSections.tab==='top'))void load();
}

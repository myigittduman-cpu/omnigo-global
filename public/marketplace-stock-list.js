import {api} from './api.js';
import {canUseMarketplaceStockPilot,marketplaceStockPage} from './marketplace-stock-pilot.js';
import {bindListingEdits} from './marketplace-listing-edits.js';
import {stockListingPages,stockListingRows,stockListingsShell,stockProviderOptions} from './marketplace-stock-list-view.js';
import {paintContent} from './content-paint.js';
import {bindLiveSearch} from './live-search.js';
import {latestPageRead} from './latest-page-read.js';
import {pageSnapshot} from './workspace-page-cache.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
import {markPageUpdated} from './page-refresh.js';
import {scrollTableStart} from './table-page-scroll.js';
import {hasTableTextSelection} from './table-text-selection.js';

export function bindMarketplaceStockList(app,state){
 const root=app.querySelector('[data-stock-listings]');
 if(!root||root.pageRefresh||!canUseMarketplaceStockPilot(state))return;
 root.setAttribute('data-page-scope','');
 const token=workspaceToken(state);
 const current=()=>root.isConnected&&sameWorkspace(state,token)&&state.view===marketplaceStockPage&&canUseMarketplaceStockPilot(state);
 const model={query:'',provider:'',page:0,data:null};
 const snapshot=pageSnapshot(state,marketplaceStockPage,['query','provider','page','data']);
 snapshot.restore(model);
 root.innerHTML=stockListingsShell(model);
 const form=root.querySelector('form'),body=root.querySelector('tbody'),status=root.querySelector('[data-stock-list-status]');
 const pager=root.querySelector('[data-stock-list-pager]'),frame=root.querySelector('[data-stock-list-frame]');
 const reads=latestPageRead(model,current);
 let providers=model.data?.providers||[];
 const edits=bindListingEdits(root,{company:token.company,current,
  itemFor:key=>model.data?.items.find(item=>(item.cardKey??item.key)===key),refresh:()=>refresh()});
 function paint(){
  const data=model.data;
  paintContent(body,data?stockListingRows(data.items):'');
  paintContent(pager,data?stockListingPages(data):'');
  if(data)providers=data.providers;
  paintContent(form.elements.provider,stockProviderOptions(providers,model.provider));
  status.textContent=!data?'Pazaryeri stokları yükleniyor…':data.total?data.total.toLocaleString('tr-TR')+' stok kartı':'Bu filtrelere uygun doğrulanmış aktif ilan bulunamadı.';
 }
 async function refresh({background=false,scroll=false}={}){
  if(!current()||(background&&(edits.busy()||model.loading||hasTableTextSelection(root)||model.query!==form.elements.query.value.trim())))return;
  const read=reads.begin();
  if(!background)frame.setAttribute('aria-busy','true');
  const params=new URLSearchParams({company:token.company,query:model.query,provider:model.provider,page:model.page});
  try{
   const data=await api('/inventory/active-marketplace-listings?'+params,'GET',undefined,{signal:read.signal});
   if(!read.active())return;
   if(background&&edits.busy())return;
   model.page=data.page;model.data=data;snapshot.save(model);paint();markPageUpdated();
   if(scroll)scrollTableStart(root,'[data-stock-list-frame]');
  }catch(failure){
   if(!read.active())return;
   if([401,403,404].includes(failure.status)){model.data=null;snapshot.save(model);paint();}
   if(model.data)model.page=model.data.page;
   status.textContent=failure.message||'Pazaryeri stokları okunamadı.';
  }finally{if(read.finish())frame.removeAttribute('aria-busy');}
 }
 function apply(){
  const query=form.elements.query.value.trim(),provider=form.elements.provider.value;
  if(query!==model.query||provider!==model.provider)Object.assign(model,{query,provider,page:0,data:null});
  if(!model.data)paint();
  return refresh();
 }
 bindLiveSearch(root,{formSelector:'[data-stock-list-filters]',fieldName:'query',current});
 form.onsubmit=event=>{event.preventDefault();if(current())void apply();};
 form.elements.provider.onchange=()=>form.requestSubmit();
 root.addEventListener('click',event=>{
  const button=event.target.closest('button');
  if(!current()||!model.data||!button||button.disabled)return;
  if(button.hasAttribute('data-stock-list-page')){
   const page=Number(button.dataset.stockListPage);
   if(!Number.isSafeInteger(page)||page<0||page>=Math.ceil(model.data.total/model.data.pageSize)||page===model.page)return;
   model.page=page;void refresh({scroll:true});
  }

 });
 root.pageRefresh=apply;root.stockListingsRefresh=()=>refresh({background:true});
 paint();void refresh();
}

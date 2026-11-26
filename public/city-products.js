import {api} from './api.js';
import {pageSnapshot} from './workspace-page-cache.js';
import {cityProductChooser,cityProductRows,cityProductsMessage,cityProductsPager} from './city-products-view.js';
import {bindCityProductOutputs} from './city-products-output-actions.js';
import {bindCityProductFilters} from './city-products-filters.js';
import {paintContent} from './content-paint.js';
import {customerCan} from './customer-access.js';
import {latestPageRead} from './latest-page-read.js';
import {markPageUpdated} from './page-refresh.js';
import {bindShipmentOperations} from './shipment-operations.js';
import {scrollTableStart} from './table-page-scroll.js';
import {hasTableTextSelection} from './table-text-selection.js';
import {cityProductCities,workspaceCities} from './turkey-cities.js';
import {sameWorkspace,workspaceToken} from './workspace-context.js';
export {cityProductsContent} from './city-products-view.js';

export function bindCityProducts(app,state){
 const root=app.querySelector('[data-city-products]');
 if(!root||root.pageRefresh||!state.user||!customerCan(state,'cityProducts'))return;
 const token=workspaceToken(state),role=state.user.role;
 const current=()=>root.isConnected&&sameWorkspace(state,token)&&state.user?.role===role&&state.view==='cityProducts';
 const model={city:'all',sort:'newest',page:0,data:null,loading:false};
 const snapshot=pageSnapshot(state,'cityProducts',['city','sort','page','data']);
 snapshot.restore(model);
 let requested={city:model.city,sort:model.sort,page:model.page};
 let displayedCities=cityProductCities(workspaceCities(state));
 const reads=latestPageRead(model,current);
 const chooser=root.querySelector('[data-products-chooser]'),panel=root.querySelector('#city-products-panel');
 const filters=bindCityProductFilters(root,{
  apply:next=>{if(current())void refresh(next);}});
 const body=root.querySelector('tbody'),status=root.querySelector('[data-products-status]'),pager=root.querySelector('[data-products-pages]');
 const menus=bindShipmentOperations(root);
 const outputs=bindCityProductOutputs(root,{state,company:token.company,current,model});
 let retry=null;
 function paint(data,error=''){
  const available=data?.cities??[];
  if(available.length!==displayedCities.length||available.some((city,index)=>city!==displayedCities[index])){
   paintContent(chooser,cityProductChooser(available,data?.city));
   displayedCities=available;
  }
  filters.showValues(model);
  const opened=menus.capture();
  if(paintContent(body,data?cityProductRows(data):''))menus.restore(opened);
  paintContent(status,cityProductsMessage(data,error));
  paintContent(pager,cityProductsPager(data));
  outputs.sync();
 }
 async function refresh({city=requested.city,sort=requested.sort,page=requested.page,background=false,manual=false,scroll=false}={}){
  if(!current()||(background&&model.loading))return false;
  if(!customerCan(state,'cityProducts')){reads.cancel();outputs.reset();model.data=null;paint(null,'Bu tabloyu görüntüleme yetkiniz yok.');return false;}
  const request=reads.begin();
  requested={city,sort,page};
  if(!background)panel.setAttribute('aria-busy','true');
  if(city!==model.city||sort!==model.sort||!customerCan(state,'cityProducts','export'))outputs.reset();
  else outputs.sync();
  const query=new URLSearchParams({company:token.company,page,sort,group:'order'});
  if(city)query.set('city',city);
  if(background&&model.data?.revision&&city===model.city&&sort===model.sort&&page===model.page)query.set('ifRevision',model.data.revision);
  try{
   const data=await api('/city-products?'+query,'GET',undefined,{signal:request.signal});
   if(!request.active())return false;
   if(!customerCan(state,'cityProducts')){model.data=null;outputs.reset();paint(null,'Bu tabloyu görüntüleme yetkiniz yok.');return false;}
   if(data.unchanged&&query.has('ifRevision')){
    if(retry?.city===city&&retry.sort===sort&&retry.page===page){retry=null;paintContent(status,cityProductsMessage(model.data,''));}
    markPageUpdated();
    return true;
   }
   if((data.sort??'default')!==sort||data.city!==city)throw new Error('Seçili il ve sıralama doğrulanamadı. Yeniden deneyin.');
   // Allow the current read to finish so revoked city access clears even while text is selected.
   const interacting=hasTableTextSelection(root)||Boolean(root.querySelector('details[open]'));
   const scopeMatches=data.cities.length===displayedCities.length&&data.cities.every((value,i)=>value===displayedCities[i]);
   if(background&&interacting&&model.data&&city===model.city&&sort===model.sort&&scopeMatches)return false;
   if(!scopeMatches)outputs.reset();
   Object.assign(model,{data,city:data.city,sort,page:data.page});
   requested={city,sort,page:data.page};
   if(!background||(retry?.city===city&&retry.sort===sort&&retry.page===page))retry=null;
   snapshot.save(model);paint(data,retry?.error);markPageUpdated();
   if(scroll){
    scrollTableStart(root,'.city-products-scroll');
    pager.querySelector('[aria-current="page"]')?.focus({preventScroll:true});
   }
   return true;
  }catch(error){
   if(!request.active())return false;
   if([401,403,404].includes(error.status)){
    Object.assign(model,{city:'all',sort,page:0,data:null});outputs.reset();
    requested={city:'all',sort,page:0};snapshot.save(model);
    retry=null;
    paint(null,error.message);
    if(error.status===403&&city&&city!=='all'&&customerCan(state,'cityProducts'))return refresh({city:'all',sort,page:0,manual});
   }else{
    // A failed page switch leaves the currently displayed city and number intact.
    if(!background||!retry)retry={city,sort,page,scroll,error:error.message};
    requested={city:model.city,sort:model.sort,page:model.page};
    filters.showValues(model,true);
    paintContent(status,cityProductsMessage(model.data,retry.error));
   }
   if(manual)throw error;
   return false;
  }finally{if(request.finish()){panel.removeAttribute('aria-busy');outputs.sync();}}
 }
 root.addEventListener('click',event=>{
  const button=event.target.closest('button');
  if(!button||!root.contains(button)||button.disabled||!current())return;
  if(button.hasAttribute('data-products-page')){
   const page=Number(button.dataset.productsPage);
   if(Number.isInteger(page)&&page>=0&&page!==requested.page)void refresh({page,scroll:true});
  }else if(button.hasAttribute('data-products-retry'))void refresh(retry??{});
 });
 root.pageRefresh=()=>refresh({manual:true});
 root.cityProductsRefresh=()=>refresh({background:true});
 if(model.data)paint(model.data);
 void refresh({background:Boolean(model.data)});
}

import {latestPageRead} from './latest-page-read.js';
import {paintContent} from './content-paint.js';
import {pageSnapshot} from './workspace-page-cache.js';
import {scrollTableStart} from './table-page-scroll.js';
import {bindLiveSearch} from './live-search.js';
import {customerCan} from './customer-access.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {markPageUpdated} from './page-refresh.js';
import {api} from './api.js';
import {returnsView} from './returns-view.js';
import {hasTableTextSelection} from './table-text-selection.js';
import {workspaceToken,sameWorkspace} from './workspace-context.js';
export function bindReturns(app,state){
 const root=app.querySelector('[data-returns]');if(!root||!customerCan(state,'returns'))return;
 const token=workspaceToken(state),version=state.data?.workspaceAccess?.version;
 const current=()=>root.isConnected&&sameWorkspace(state,token)&&state.view==='returns'
  &&state.data?.workspaceAccess?.version===version&&customerCan(state,'returns');
 const types=[];
 if(customerCan(state,'returns','viewCancelledRecords'))types.push('cancellation');
 if(customerCan(state,'returns','viewReturnedRecords'))types.push('return');
 if(types.length===2)types.unshift('all');
 const model={provider:'all',type:types[0],types,status:'all',search:'',page:0,data:null,error:'',loading:false};
 const filterFields=['provider','type','status','search'];
 const reads=latestPageRead(model,current);
 const snapshot=pageSnapshot(state,'returns',['data',...filterFields,'page']);snapshot.restore(model);
 if(!types.includes(model.type)){model.type=types[0];model.data=null;model.page=0;}
 const search=bindLiveSearch(root,{formSelector:'[data-return-filters]',fieldName:'search',current,isBusy:()=>model.loading});
 const paint=()=>{if(current())search.paint(()=>{paintContent(root,returnsView(model));});};
 async function refresh(automatic=false,page=model.page,filters=model){
  const blocked=()=>automatic&&hasTableTextSelection(root);
  if(!current()||(automatic&&model.loading)||blocked())return false;
  const request=reads.begin();let loaded=false;model.error='';
  const query=new URLSearchParams({company:token.company,page});
  for(const name of filterFields)query.set(name,filters[name]);
  root.setAttribute('aria-busy','true');
  if(!model.data)paint();
  const path=state.user?.role==='admin'?'/admin/returns':'/returns';
  try{const data=await api(path+'?'+query,'GET',undefined,{signal:request.signal});if(request.active()&&!blocked()){
   for(const name of filterFields)model[name]=filters[name];
   model.data=data;markPageUpdated();model.page=data.page;snapshot.save(model);loaded=true;
  }}
  catch(error){if(request.active()&&error.name!=='AbortError')model.error=error.message;}
  finally{if(request.finish()){root.removeAttribute('aria-busy');if(!blocked())paint();}}
  return loaded;
 }
 root.addEventListener('submit',event=>{if(!event.target.matches('[data-return-filters]'))return;event.preventDefault();void refresh(false,0,Object.fromEntries(new FormData(event.target)));});
 root.addEventListener('change',event=>{if(['provider','type','status'].includes(event.target.name))event.target.form.requestSubmit();});
 root.addEventListener('click',event=>{
  const button=event.target.closest('button');
  if(!button||button.disabled||model.loading||!button.hasAttribute('data-returns-page'))return;
  void refresh(false,Number(button.dataset.returnsPage)).then(loaded=>{if(loaded&&current()){
   root.querySelector('[aria-current=page]')?.focus({preventScroll:true});
   scrollTableStart(root,'.return-table-wrap');
  }});
 });
 root.returnsRefresh=()=>{if(model.loading)return;const form=root.querySelector('form');if(!form||root.contains(document.activeElement)||filterFields.some(field=>form.elements[field].value!==model[field]))return;return refresh(true);};
 root.pageRefresh=async()=>{if(model.loading)throw new Error(i18nT('Talepler yükleniyor.'));const fields=Object.fromEntries(new FormData(root.querySelector('form')));await refresh(false,filterFields.some(key=>fields[key]!==model[key])?0:model.page,fields);if(model.error)throw new Error(model.error);};
 paint();void refresh();
}

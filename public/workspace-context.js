import {clearPageSnapshots} from './workspace-page-cache.js';
import {t as i18nT} from './i18n.js';
export function clearWorkspace(state){
 clearPageSnapshots();
 state.inboxConversation=null;state.inboxOpening=null;state.inboxProvider='';state.inboxSearch='';state.inboxStatus='waiting';
 state.inboxSort='newest';
 state.inboxFiltersOpen=false;state.inboxDownloading=false;
 state.reportFilters=null;state.healthPage=0;state.healthSearch='';state.paymentDay='';
 state.contextRevision=(state.contextRevision||0)+1;
 state.notificationData=null;state.notificationError='';state.notificationPage=0;state.notificationRequest=0;
 state.notificationFilter='all';state.notificationBusy=false;state.notificationConfirmDelete=false;state.notificationActionError='';
 Object.assign(state,{company:null,data:null,shipmentSelection:null,inboxData:null,inboxError:'',inboxPage:0,inboxUnreadOnly:false,supportData:null,supportError:'',supportPage:0,filter:'all',view:'overview'});
}
export function selectWorkspace(state,id){
 const company=state.companies.find(item=>Number(item.id)===Number(id));
 if(!company)throw new Error(i18nT('Müşteri bulunamadı. Listeyi yenileyin.'));
 clearWorkspace(state);state.company=Number(company.id);return company;
}
// The selected customer remains active across page navigation.
export function selectCustomerWorkspace(state,id){
 if(state.user?.role!=='admin')throw new Error(i18nT('Müşteri değiştirme yetkiniz yok.'));
 const view=state.view;
 const company=selectWorkspace(state,id);state.view=view;return company;
}
export const hasWorkspace=state=>Boolean(state.user&&state.company&&state.data);
export const workspaceToken=state=>({user:state.user?.id,company:state.company,revision:state.contextRevision||0});
export const sameWorkspace=(state,token)=>state.user?.id===token.user&&state.company===token.company&&(state.contextRevision||0)===token.revision;

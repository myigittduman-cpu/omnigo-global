import {sameWorkspace} from './workspace-context.js';
import {notificationDetailShell} from './notification-detail.js';
import {openOperationNotification} from './operation-notification.js';
import {marketplaceImportReportView,marketplaceImportTitle,isLiveImportSnapshot} from './marketplace-import-report.js';

export function openInventoryImportReport(run,state,token){
 if(state.user?.role!=='admin'||!sameWorkspace(state,token)||!run)return;
 if(isLiveImportSnapshot(run)){
  const shell=notificationDetailShell(state,token,'Mevcut bağlantının aktarım durumu','event-notification-dialog marketplace-import-dialog');
  shell.show(marketplaceImportReportView(run));return shell.dialog;
 }
 const event={events:[run.state==='failed'?'marketplace_import_failed':'marketplace_import_completed'],importReport:run,provider:run.providerLabel||run.provider};
 const noticeId=Number(run.noticeId);
 if(['complete','failed'].includes(run.state)&&Number.isSafeInteger(noticeId)&&noticeId>0)return openOperationNotification({...event,id:noticeId},state,token);
 const title=['complete','failed'].includes(run.state)?marketplaceImportTitle(event):'Pazaryeri aktarım ayrıntıları';
 const shell=notificationDetailShell(state,token,title,'event-notification-dialog marketplace-import-dialog');
 shell.show(marketplaceImportReportView(event));return shell.dialog;
}

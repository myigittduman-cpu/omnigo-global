import {api} from './api.js';
import {notificationDetailShell} from './notification-detail.js';
import {openTicket} from './support.js';
export async function openSupportResolution(item,state,token,render){
 const shell=notificationDetailShell(state,token,'Destek konuşması','support-detail-dialog');
 try{
  const detail=await api(`/notifications/resolved/${item.id}?company=${token.company}`,'GET',undefined,{signal:shell.signal});
  if(!shell.active())return shell.dialog;
  await openTicket(detail.targetId,state,render,0,{host:shell.dialog,onLoaded:()=>api(`/notifications/resolved/${item.id}/read?company=${token.company}`,'POST',{})});
 }catch(error){shell.fail(error);}
 return shell.dialog;
}

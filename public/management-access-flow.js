import {clearManagementGrants,managementLease} from './management-access-state.js';

const flows=new WeakMap();
export function releaseManagementAccess(){
 // Closing a menu must not revoke the fixed, server-owned 15-minute user lease.
 clearManagementGrants();return Promise.resolve();
}
/** Expiration covers the existing form in place, preserving unsaved fields. */
export function protectManagementDialog(dialog){
 if(flows.has(dialog))return;
 let timer,stopped=false;
 const check=async()=>{
  clearTimeout(timer);if(stopped||!dialog.open)return;
  const lease=managementLease();if(!lease?.expiresAt)return;
  const remaining=lease.deadline-Date.now();
  if(remaining>0){timer=setTimeout(check,Math.min(remaining+50,2147483647));return;}
  const {renewMarketplaceAccess}=await import('./marketplace-access.js');
  try{if(!stopped&&dialog.open)await renewMarketplaceAccess(lease.company,()=>!stopped&&dialog.open);}
  catch{if(!stopped&&dialog.open)timer=setTimeout(check,5000);return;}
  if(!stopped&&dialog.open&&managementLease()?.deadline>Date.now())void check();
 };
 const closed=()=>{if(dialog.open)return;stopped=true;clearTimeout(timer);globalThis.document?.removeEventListener('visibilitychange',check);dialog.removeEventListener('close',closed);flows.delete(dialog);};
 flows.set(dialog,true);
 dialog.addEventListener('close',closed);globalThis.document?.addEventListener('visibilitychange',check);void check();
}

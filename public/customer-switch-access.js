import {api} from './api.js';

/** Read-only server authorization reuses the fixed 15-minute Omnigo Admin lease. */
export async function authorizeCustomerSwitch(company,current=()=>true){
 const id=Number(company.id);if(!Number.isSafeInteger(id)||id<1||!current())return false;
 try{
  const result=await api('/admin/workspace-access?company='+id);
  return current()&&result.allowed===true&&Number(result.companyId)===id;
 }catch(error){
  if(error.status===423)return false; // Closing the password prompt keeps the old customer and draft.
  throw error;
 }
}

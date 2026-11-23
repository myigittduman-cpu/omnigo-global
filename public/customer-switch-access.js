import {api} from './api.js';

/** Verify that the selected store is available in the current administrator session. */
export async function authorizeCustomerSwitch(company,current=()=>true){
 const id=Number(company.id);if(!Number.isSafeInteger(id)||id<1||!current())return false;
 const result=await api('/admin/workspace-access?company='+id);
 return current()&&result.allowed===true&&Number(result.companyId)===id;
}

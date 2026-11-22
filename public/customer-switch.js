import {confirmCustomerSwitch,coverCustomerSwitch} from './customer-switch-screen.js';
import {authorizeCustomerSwitch} from './customer-switch-access.js';

let handler=null;
export function configureCustomerSwitch(callback){handler=callback;}
/** Explicit selectors share this flow; notification deep links retain their own destination. */
export function requestCustomerSwitch(id){return handler?handler(Number(id)):null;}

export async function transitionCustomer(company,{canLeave,switchWorkspace,current=()=>true,ask=confirmCustomerSwitch,cover=coverCustomerSwitch,authorize=authorizeCustomerSwitch}){
 if(!current()||!await ask(company.name)||!current())return false;
 // Authenticate before any draft may be marked as deliberately discarded.
 if(!await authorize(company,current)||!current())return false;
 if(!await canLeave()||!current())return false;
 return cover(company,async()=>current()?switchWorkspace():false);
}

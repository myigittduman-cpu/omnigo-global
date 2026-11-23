const key='omnigo-management-window-grant';
const isGrant=value=>typeof value==='string'&&/^[a-f0-9]{64}$/.test(value);
let grant='';
try{
 const reloaded=globalThis.performance?.getEntriesByType('navigation')[0]?.type==='reload';
 const saved=reloaded?globalThis.sessionStorage?.getItem(key):null;
 if(isGrant(saved))grant=saved;
 else globalThis.sessionStorage?.removeItem(key);
}catch{}

export function managementGrant(){return grant;}
export function rememberManagementGrant(result){
 if(!isGrant(result?.grant))throw new Error('Yönetim doğrulaması tamamlanamadı.');
 grant=result.grant;
 // A reload in this window retains the opaque proof. A new window starts locked.
 try{globalThis.sessionStorage?.setItem(key,grant);}catch{}
}
export function clearManagementGrants(){
 grant='';
 try{globalThis.sessionStorage?.removeItem(key);}catch{}
}

// Opaque server grants live only in this page's memory, never browser storage.
const grants=new Map();
export function managementGrant(company){return grants.get(Number(company))?.grant||grants.get(0)?.grant||'';}
export function rememberManagementGrant(company,result){
 if(!/^[a-f0-9]{64}$/.test(result?.grant||''))throw new Error('Yönetim doğrulaması tamamlanamadı.');
 grants.set(Number(company),{grant:result.grant,expiresAt:result.expiresAt});
}
let lease=null;
export function rememberManagementLease(company,result){
 const now=Date.now(),expiresAt=Number(result?.expiresAt),serverNow=Number(result?.serverNow??Math.floor(now/1000));
 lease=result?.granted?{company:Number(company),expiresAt,deadline:now+Math.max(0,expiresAt-serverNow)*1000}:null;
}
export function managementLease(){return lease;}
export function clearManagementGrants(){const prior=[...grants];grants.clear();lease=null;return prior;}

export const permissionDefaults=(configuration,userId)=>
 configuration.roleDefaults?.[configuration.roles?.[userId]]||configuration.defaults;

/** Prepare a single, version-checked permissions save without mutating the editor draft. */
export function applyPermissionsToUsers(configuration,{users,selectedIds,sourceUserId='',page='all',catalog}){
 const allowed=new Set(users.map(user=>String(user.id))),ids=[...new Set([...selectedIds].map(String))];
 if(ids.some(id=>!allowed.has(id))||(sourceUserId&&!allowed.has(String(sourceUserId))))throw new Error('Yalnız bu mağazanın kullanıcıları seçilebilir.');
 if(page!=='all'&&!Object.hasOwn(catalog,page))throw new Error('Uygulanacak bölüm geçersiz.');
 const next=structuredClone(configuration),source=structuredClone(configuration.users[sourceUserId]||permissionDefaults(configuration,sourceUserId));
 for(const id of ids){
  if(page==='all')next.users[id]=structuredClone(source);
  else{next.users[id]=structuredClone(next.users[id]||permissionDefaults(configuration,id));next.users[id][page]=structuredClone(source[page]);}
 }
 return next;
}

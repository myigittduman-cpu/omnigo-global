const groups=new Set(['management','operations','finance','system']);
const pattern=/^customPage\.(management|operations|finance|system)\.([a-z][a-z0-9-]{2,39})$/;
export const isCustomPageView=view=>typeof view==='string'&&pattern.test(view);
export const customPageGroup=view=>typeof view==='string'?view.match(pattern)?.[1]:undefined;
export const customPageKey=page=>`customPage.${groups.has(page.menuGroup)?page.menuGroup:'management'}.${page.slug}`;
export function customPageItems(state){
 const role=state.user?.role,company=Number(state.company);
 if(!['admin','client'].includes(role)||!Number.isSafeInteger(company)||company<1)return [];
 if(role==='client'&&Number(state.user.companyId??state.user.company_id)!==company)return [];
 const items=state.data?.customPages?.items;
 if(!Array.isArray(items))return [];
 return items.filter(page=>
  page&&/^[a-z][a-z0-9-]{2,39}$/.test(page.slug)&&[role,'both'].includes(page.role)&&
  (page.scope==='all'||page.scope==='selected'&&page.companyIds?.includes(company)));
}
export const customPageForView=(state,view=state.view)=>customPageItems(state).find(page=>customPageKey(page)===view);
export const customPageLabels=state=>Object.fromEntries(customPageItems(state).map(page=>[customPageKey(page),page.title]));

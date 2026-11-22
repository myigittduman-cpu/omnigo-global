const logistics='accounting_logistics',manager='store_manager';
const logisticsLegacyPages=new Set(['overview','overviewTop','overviewWork','orders','inventory',
 'shipments','deliveries','cityProducts','shipmentInvoices']);
const supportAudiences={accounting_logistics:'hidden',admin_logistics:'admin',store_roles:'store_manager',all_roles:'admin_manager'};

export const sectionAudiences={
 admin:['Omnigo',['admin']],
 accounting_logistics:['Muhasebe & Lojistik',[logistics]],
 store_manager:['Mağaza Yetkilisi',[manager]],
 admin_logistics:['Omnigo / Muhasebe & Lojistik',['admin',logistics]],
 admin_manager:['Omnigo / Mağaza Yetkilisi',['admin',manager]],
 store_roles:['Muhasebe & Lojistik / Mağaza Yetkilisi',[logistics,manager]],
 all_roles:['Tüm roller',['admin',logistics,manager]],
 hidden:['Hiçbir rolde gösterme',[]]
};

export function sectionAudienceSelection(page,audience){
 if(audience==='shown')return logisticsLegacyPages.has(page)?'all_roles':'admin_manager';
 if(audience==='client')return logisticsLegacyPages.has(page)?'store_roles':'store_manager';
 if(page==='support'&&Object.hasOwn(supportAudiences,audience))return supportAudiences[audience];
 return Object.hasOwn(sectionAudiences,audience)?audience:'hidden';
}

export const sectionAllowsRole=(page,audience,role)=>sectionAudiences[sectionAudienceSelection(page,audience)][1].includes(role==='store_admin'?manager:role);
export const sectionHasStoreRole=(page,audience)=>sectionAudiences[sectionAudienceSelection(page,audience)][1].some(role=>role!=='admin');
export const sectionEnabled=(page,audience)=>sectionAudienceSelection(page,audience)!=='hidden';

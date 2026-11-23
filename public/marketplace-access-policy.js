export function marketplaceAccessCompany(path,method='GET',data){
 const url=new URL(path,'https://local.invalid'),route=url.pathname;
 if(route==='/management/logo'&&method==='GET')return null;
 if(/^\/admin\/users(?:\/\d+(?:\/(?:portrait|sessions))?)?$/.test(route)
  ||['/management/permissions-bulk','/management/role-defaults'].includes(route))return 0;
 if(route==='/users'&&method==='POST'){const id=Number(data?.companyId);return Number.isSafeInteger(id)&&id>0?id:null;}
 if(route==='/management/marketplaces/start')return null;
 if(!['/admin/marketplace-access','/management/menu-labels'].includes(route)&&!/^\/(?:management\/(?:marketplaces(?:-bulk|\/[a-z0-9]+)?|stores(?:\/\d+)?|permissions|customer|initial-password|logo|users\/\d+(?:\/(?:reset|sessions|password))?)|integrations\/(?:koctas|hepsiburada|n11|pazarama|pttavm|idefix|trendyol)(?:\/credentials)?)$/.test(route))return null;
 const company=Number(url.searchParams.get('company'));return Number.isSafeInteger(company)&&company>=0?company:null;
}

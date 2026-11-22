export const marketplaceStockPage='marketplaceStocks';

export function canUseMarketplaceStockPilot(state){
 const company=Number(state.company);
 return state.user?.role==='admin'&&company>0&&Array.isArray(state.companies)&&
  state.companies.some(item=>Number(item.id)===company&&item.marketplaceStockPilot===true);
}

export function marketplaceStockGroupPages(group,state){
 if(group.id!=='operations'||!canUseMarketplaceStockPilot(state))return group.pages;
 return group.pages.flatMap(page=>page==='inventory'?[page,marketplaceStockPage]:[page]);
}

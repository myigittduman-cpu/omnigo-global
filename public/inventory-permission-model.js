export function inventoryPermissionAvailable(mode,action){
 if(mode==='marketplace'&&['editStock','recordStoreSale'].includes(action))return false;
 return !(mode==='store'&&action==='viewMarketplaceStocks');
}

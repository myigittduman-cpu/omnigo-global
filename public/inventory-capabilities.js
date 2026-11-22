import {customerCan} from './customer-access.js';
export const inventoryActions=['view','viewPurchasePrice','editPurchasePrice','viewMovements','viewSales','viewMarketplaceStocks','editStock','recordStoreSale'];
export function inventoryCapabilities(state,server){
 return Object.fromEntries(inventoryActions.map(action=>[action,state.user?.role==='admin'||
  !(action==='viewMarketplaceStocks'&&state.data?.workspaceAccess?.storeRole==='accounting_logistics')&&
  server?.[action]!==false&&customerCan(state,'inventory',action)]));
}
/** Legacy defaults preserve existing readers; the controller always supplies current server/user grants. */
export function inventoryModelCapabilities(model){
 return model.capabilities||Object.fromEntries(inventoryActions.map(action=>[action,model.admin||!['editStock','recordStoreSale'].includes(action)]));
}

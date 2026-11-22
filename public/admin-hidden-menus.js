import {canUseMarketplaceStockPilot,marketplaceStockPage} from './marketplace-stock-pilot.js';
/** Administrative links from notifications retain access to enabled modules. */
export function adminCanOpenHiddenPage(state,page){
 if(page===marketplaceStockPage)return canUseMarketplaceStockPilot(state);
 return state.user?.role==='admin'&&(!['shipments','deliveries'].includes(page)||state.data?.modules?.[page]!==false);
}

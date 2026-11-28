import {customerPageVisible} from './customer-access.js';
import {customerOverviewMenu} from './customer-overview-menu.js';

/** Store presentation preference; child permissions remain independent. */
export function usesCustomerOverviewTabs(state){
 return state.user?.role==='client'&&state.data?.workspaceAccess?.overviewLayout==='tabs'&&customerPageVisible(state,'overview');
}

export function customerOverviewTarget(state,page){
 return usesCustomerOverviewTabs(state)&&Object.hasOwn(customerOverviewMenu,page)&&customerPageVisible(state,page)
  ?customerOverviewMenu[page].section:null;
}

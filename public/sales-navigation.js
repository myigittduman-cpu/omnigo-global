import {customerPageVisible} from './customer-access.js';

/** Reuse the existing sales permission when the overview is not granted. */
export function salesHomePage(state) {
 if(customerPageVisible(state,'overview'))return 'overview';
 return customerPageVisible(state,'overviewSales')?'overviewSales':'channels';
}

export function consolidateSalesNavigation(names,state) {
 const result = {...names};
 if (Object.hasOwn(result,'overview')||state.data?.workspaceAccess?.grants?.overviewSales) delete result.channels;
 else if (Object.hasOwn(result,'channels')) result.channels = 'Genel bakış';
 return result;
}

export const usesCustomerWorkspaceStandard=state=>state.user?.role==='client'&&state.data?.workspaceAccess?.interfacePreset==='customer-standard';

/** Presentation only; existing customer grants decide which entries are actually visible. */
export function customerWorkspaceGroups(state,groups){
 if(!usesCustomerWorkspaceStandard(state))return groups;
 return groups.map(group=>group.id==='management'
  ?{...group,pages:['overview','fileCenter','orders',...group.pages.filter(page=>!['overview','fileCenter','orders'].includes(page))]}
  :group.id==='operations'?{...group,pages:group.pages.filter(page=>page!=='orders')}:group);
}

export const customerWorkspaceDefaultLabel=(state,key,fallback)=>usesCustomerWorkspaceStandard(state)&&key==='shipments'?'Kargo Fişleri':fallback;

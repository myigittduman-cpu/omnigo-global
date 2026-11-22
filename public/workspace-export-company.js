/** Use only branding from the active workspace or its matching directory entry. */
export function workspaceExportCompany(state, companyId){
 const id=Number(companyId);
 if(!Number.isSafeInteger(id)||id<1||(state.company!=null&&Number(state.company)!==id))return;
 if(state.user?.role==='client'&&state.user.companyId!=null&&Number(state.user.companyId)!==id)return;
 const active=state.data?.company;
 const listed=state.companies?.find(company=>Number(company.id)===id);
 if(Number(active?.id)!==id)return listed;
 if(typeof listed?.hasLogo!=='boolean')return active;
 if(Number(listed.brandingVersion)>Number(active.brandingVersion||0))return listed;
 if(typeof active.hasLogo==='boolean')return active;
 return {...active,hasLogo:listed.hasLogo,brandingVersion:listed.brandingVersion};
}

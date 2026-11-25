import {workspaceExportCompany} from './workspace-export-company.js';

export function isTerziogluWorkspace(state){
 const company=workspaceExportCompany(state,state.company);
 const name=String(company?.name??'').trim().toUpperCase().replaceAll('İ','I');
 return name==='TERZIOĞLU SHOP';
}

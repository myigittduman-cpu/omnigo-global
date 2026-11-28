import {defaultCities,workspaceCities} from './turkey-cities.js';
import {customerSensitiveVisibility} from './customer-sensitive-visibility.js';
import {workspaceExportCompany} from './workspace-export-company.js';
export function deliveryWorkspaceScope(state={}){
 const availableCities=[...workspaceCities(state)];
 return {availableCities,showCityFilter:Number(state.company)!==1,exportCompany:workspaceExportCompany(state,state.company),shippingMode:state.data?.shippingScope?.mode||(availableCities.length===81?'nationwide':'regional'),role:state.user?.role||'admin',sensitiveVisibility:state.user?.role?customerSensitiveVisibility(state,{},'deliveries'):{cancellations:true,returns:true}};
}
/** Regional cards remain unchanged; nationwide workspaces show only occupied provinces. */
export function deliveryCardCities(model){
 if(model.shippingMode!=='nationwide')return model.availableCities||defaultCities;
 return (model.availableCities||[]).filter(city=>model.data?.cities.some(row=>row.name===city&&row.count>0)||(model.cities||[]).includes(city)||model.city===city);
}
export function applyDeliveryScope(model,data){
 if(data.sensitiveVisibility)model.sensitiveVisibility=data.sensitiveVisibility;
 if(!Array.isArray(data.cities)||(!data.shippingScope&&!data.cities.length))return false;
 const available=data.cities.filter(row=>!['all','Diğer / Kontrol'].includes(row.name)).map(row=>row.name);
 model.availableCities=available;model.shippingMode=data.shippingScope?.mode||(available.length===81?'nationwide':'regional');let reset=false;
 if(model.cities){const narrowed=model.cities.filter(city=>available.includes(city));if(narrowed.length!==model.cities.length){model.cities=narrowed.length?narrowed:null;model.city=narrowed.length===1?narrowed[0]:'all';reset=true;}}
 else if(!['all','Diğer / Kontrol',...available].includes(model.city)){model.city=model.shippingMode==='nationwide'?'all':available[0]||'all';reset=true;}
 if(reset){model.page=0;model.selected=new Set();model.manualSelection=false;model.cityDraft=null;model.cityFilterOpen=false;}return reset;
}

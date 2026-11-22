import {api} from './api.js';
import {currentLanguage,setLanguage} from './i18n.js';
export const themeLabels=Object.freeze({
 'classic-navy':'Omnigo Klasik Lacivert (varsayılan)',
 light:'Fildişi (açık)',
 burgundy:'Bordo ve Porselen',
 'petrol-smoke':'Petrol ve Duman',
 'graphite-copper':'Grafit ve Bakır'
});
export const preferenceDefaults=()=>({theme:'classic-navy',fontSize:'standard',fontFamily:'system',density:'comfortable',reducedMotion:false,language:'tr',shipmentLayouts:{}});
export const themeFor=value=>Object.hasOwn(themeLabels,value)?value:preferenceDefaults().theme;
export function applyPreferences(values={...preferenceDefaults(),language:currentLanguage()},root=document.documentElement){
 const defaults=preferenceDefaults();
 root.dataset.theme=themeFor(values.theme);
 for(const key of ['fontSize','fontFamily','density'])root.dataset[key]=defaults[key];
 root.dataset.reducedMotion=String(values.reducedMotion===true);
 if(values.language!==undefined){root.lang=setLanguage(values.language);}
}
export async function loadPreferences(user){
 const result=await api('/account/preferences');user.preferences=result;applyPreferences(result.preferences);return result;
}

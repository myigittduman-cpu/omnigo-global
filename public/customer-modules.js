import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {customerPageVisible} from './customer-access.js';
import {consolidateSalesNavigation} from './sales-navigation.js';
import {customPageLabels} from './custom-page-navigation.js';
import {customerOverviewTarget} from './customer-overview-layout.js';
export const customerModuleEnabled=(state,page)=>customerPageVisible(state,page);
export const customerPageNames=(names,state)=>Object.fromEntries(Object.entries(consolidateSalesNavigation(Object.fromEntries(Object.entries({...names,...customPageLabels(state)}).filter(([page])=>page!=='customPages'&&customerModuleEnabled(state,page)&&!customerOverviewTarget(state,page))),state)).map(([page,label])=>[page,i18nT(label)]));
export function customerModuleChoices(){
 return i18nHtml('<fieldset class="customer-module-choices full-width"><legend>Bu müşteride hangi bölümler bulunsun?</legend><label><input type="checkbox" name="shipmentsEnabled"><span>Şehir Dışı Sevkiyat</span></label><label><input type="checkbox" name="deliveriesEnabled"><span>Şehir Dışı Teslimatlar</span></label><small>Kurulumdan sonra menüyü gizlemek çalışan veri aktarımını durdurmaz. Tablolar boş başlar; bağlantılar bu müşterinin kendi API bilgileriyle kurulur.</small></fieldset>');
}

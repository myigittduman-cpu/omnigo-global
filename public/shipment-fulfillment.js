import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';
export function fulfillmentNote(row){
 return i18nHtml`<div class="fulfillment-state"><strong>Durum: ${esc(i18nT(row.fulfillmentStatusText||'Sevk bekliyor'))}</strong><span>Pazaryeri: ${esc(row.marketplaceStatusText||'Bilgi yok')}</span>${row.manualStatusOverride&&!row.dispatchTracked?i18nHtml('<small>Elle seçilen durum korunuyor.</small>'):''}</div>`;
}

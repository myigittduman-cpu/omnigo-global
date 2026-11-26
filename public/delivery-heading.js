import {html} from './i18n.js';
import {esc} from './ui.js';
import {deliveryCityFilter} from './delivery-city-filter.js';

/** Nationwide delivery uses one city selector instead of up to 81 repeated cards. */
export function deliveryTableScope(model){
 if(model.shippingMode!=='nationwide')return '';
 const total=(model.navigation?.cities??model.data?.cities)?.find(city=>city.name==='all');
 return html`<section class="delivery-national-scope delivery-table-scope" aria-label="Tüm Türkiye teslimat kapsamı"><div class="delivery-national-summary"><strong>Tüm Türkiye</strong><span>${esc(total?.count??'—')} sipariş · ${esc(total?.productCount??'—')} parça</span><small>81 il için ortak teslimat listesi</small></div><div class="delivery-national-choice">${deliveryCityFilter(model)}</div></section>`;
}

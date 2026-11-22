import {html} from './i18n.js';
import {esc} from './ui.js';

export function paymentMarketplaceFilter(report){
 const marketplaces=report.marketplaces||[],selected=report.channel||'all';
 if(!marketplaces.length)return '';
 return html`<label class="payment-marketplace-filter"><span>Pazaryeri</span>
  <select data-payment-marketplace>
   <option value="all" ${selected==='all'?'selected':''}>Tüm pazaryerleri</option>
   ${marketplaces.map(channel=>html`<option value="${esc(channel)}" ${selected===channel?'selected':''}>${esc(channel)}</option>`).join('')}
  </select>
 </label>`;
}

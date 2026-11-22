import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
const scope = state => `${state.company}:${state.month}`;

export function salesDetails(state, table) {
 const open = state.salesDetailsScope === scope(state) && state.salesDetailsOpen;
 return i18nHtml`<details class="sales-details panel" ${open ? 'open' : ''}>
  <summary><span class="sales-details-show">Satış detaylarını göster</span><span class="sales-details-hide">Satış detaylarını gizle</span><span class="sales-details-mark" aria-hidden="true"></span></summary>
  <div class="sales-details-content">${table}</div>
 </details>`;
}

export function bindSalesDetails(root, state) {
 const comparisons=root.querySelector('.overview-sales-details');
 if(comparisons){
  comparisons.open=state.salesComparisonScope===scope(state)&&!!state.salesComparisonOpen;
  const currentScope=scope(state);
  comparisons.addEventListener('toggle',()=>{if(!comparisons.isConnected||currentScope!==scope(state))return;state.salesComparisonScope=currentScope;state.salesComparisonOpen=comparisons.open;});
 }
 const details = root.querySelector('.sales-details');
 if (!details) return;
 const currentScope = scope(state);
 details.addEventListener('toggle', () => {
  if (!details.isConnected || currentScope !== scope(state)) return;
  state.salesDetailsScope = currentScope;
  state.salesDetailsOpen = details.open;
 });
}

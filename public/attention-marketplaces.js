import {html} from './i18n.js';
import {esc} from './ui.js';

export function attentionMarketplaceDetails(item){
 const markets=item.type==='inventory'?item.source?.marketplaces:null;if(!markets?.length)return '';
 const groups=[['Satışa Açık Pazaryerleri',markets.filter(m=>m.status==='active'),'is-open'],['Satışa Kapalı Pazaryerleri',markets.filter(m=>['closed','not_found','unmatched'].includes(m.status)),'is-closed'],['Durumu Doğrulanamayanlar',markets.filter(m=>m.status==='unknown'),'is-unknown']];
 return html`<div class="attention-marketplaces">${groups.filter(([,rows],i)=>i<2||rows.length).map(([label,rows,className])=>html`<div class="${className}"><strong>${label}</strong><span>${rows.length?rows.map(m=>html`<span class="attention-marketplace">${esc(m.provider)}${m.status==='not_found'||m.status==='unmatched'?html` <small>(${esc(m.label)})</small>`:''}</span>`).join(''):'Doğrulanmış pazaryeri yok.'}</span></div>`).join('')}</div>`;
}

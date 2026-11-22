import {html} from './i18n.js';
import {esc} from './ui.js';
import {deliveryQueueButtons,isDeliveryQueue} from './delivery-queues.js';

export function nationalDeliveryQueues(model){
 const review=(model.navigation?.cities??model.data?.cities)?.find(city=>city.name==='Diğer / Kontrol');
 return html`<div class="delivery-cities delivery-national-queues"><button type="button" data-city="Diğer / Kontrol" aria-pressed="${!isDeliveryQueue(model)&&model.city==='Diğer / Kontrol'}" class="${!isDeliveryQueue(model)&&model.city==='Diğer / Kontrol'?'selected':''}"><span class="delivery-city-name">Diğer / Kontrol</span><strong>${esc(review?.count??'—')} sipariş</strong><small>Eksik bilgi veya kapsam dışı il</small></button>${deliveryQueueButtons(model)}</div>`;
}

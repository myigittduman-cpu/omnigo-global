import {html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';
export const isDeliveryQueue=model=>model.queue==='cancelled';
export function deliveryQueueButtons(model){
 if(model.sensitiveVisibility?.cancellations===false)return '';
 const selected=isDeliveryQueue(model),count=(model.navigation?.queues??model.data?.queues)?.cancelled;
 return i18nHtml`<button data-delivery-queue="cancelled" aria-pressed="${selected}" class="delivery-queue-button ${selected?'selected':''}"><span>İptal Edilen Siparişler</span><strong>${esc(count??'—')}</strong><small>Aktarılan siparişlerin iptal kayıtları</small></button>`;
}
export function deliveryQueueFooter(order){
 return i18nHtml`<footer class="delivery-queue-footer"><span>${esc(order.city)} · İptal edilen ürünler teslimat evrağına eklenmez.</span><button class="btn" data-city="${esc(order.city)}">Şehrin siparişlerine git</button></footer>`;
}

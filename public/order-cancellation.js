import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';
export function cancellationState(order){
 const state=order.operationalCancellation||order.marketplaceCancellation||order.cancellation;
 if(state?.cancelledQuantity>0)return state;
 if(order.cancelCents>0)return {cancelledQuantity:null,partial:order.cancelCents<order.grossCents};
 return null;
}
export function cancellationLabel(order){
 const state=cancellationState(order);if(!state)return '';
 return i18nText`İptal edildi${state.cancelledQuantity?` · ${state.cancelledQuantity} adet`:''}`;
}
export function cancellationNotice(order){
 const label=cancellationLabel(order);
 return label?i18nHtml`<strong class="order-cancellation-note" role="note">${esc(label)}</strong>`:'';
}

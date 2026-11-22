import {ready} from './delivery-view.js';
import {deliverySelectionKey} from './delivery-dispatch-selection.js';

// Refresh data without adding new orders to a selection the user has edited.
export function refreshedDeliverySelection(model,items){
 if(model.queue!=='all')return new Set();
 return new Set(items.filter(order=>ready(order)&&(model.selected.has(deliverySelectionKey(order))||!model.manualSelection)).map(deliverySelectionKey));
}

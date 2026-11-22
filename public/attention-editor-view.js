import {html,t} from './i18n.js';
import {esc} from './ui.js';
import {attentionDecisions,attentionMarketplaceDetails} from './attention-view.js';

export function attentionEditor(item,{dismiss=false}={}){
 return html`<div class="attention-detail-layout"><section class="attention-detail-summary" aria-label="Operasyon bilgileri"><div class="operation-source"><strong>${esc(item.title)}</strong><span>${esc(item.detail)}</span></div>${attentionMarketplaceDetails(item)}</section><form class="form-grid attention-editor">
 <p class="attention-owner full-width"><strong>Omnigo</strong><span>İnceleniyor</span></p>
 ${dismiss?'<p class="form-help full-width">Yalnız bu uyarı takip listesinden kaldırılır. Ürün, sipariş ve ödeme silinmez; canlı aktarım devam eder.</p>':attentionDecisions(item)}
 <p class="form-help full-width">Pazaryerindeki durum düzeldiğinde konu listeden otomatik çıkar. Kaynak koşullar yeniden değişirse tekrar değerlendirilir.</p>
 <p class="form-error full-width" role="alert"></p><div class="operation-form-actions full-width">${dismiss?'':html`<button class="btn" type="button" data-attention-source>${item.source.kind!=='cancellation'&&['shipments','returns'].includes(item.type)?'Teslimat / iade dosyasını aç':'İlgili kaydı aç'}</button><button class="btn" type="submit" name="dismiss">Kaydı sil</button>`}<button class="btn primary" type="submit">${dismiss?'Kaydı sil':t('Kaydet')}</button></div></form></div>`;
}

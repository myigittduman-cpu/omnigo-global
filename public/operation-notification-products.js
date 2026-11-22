import {html,t} from './i18n.js';
import {esc,money} from './ui.js';

export function operationProducts(event){
 const relevant=(event.events||[]).some(value=>['cancelled','cancellation_requested','return_updated','delivery_added','shipment_added'].includes(value));
 if(!relevant)return '';
 const rows=event.products||[],returned=event.events.includes('return_updated');
 return html`<section class="notification-order-products"><h3>${t(returned?'İade talebindeki ürünler':'Sipariş ürünleri')}</h3>${rows.length?html`<div class="notification-product-table"><table><thead><tr><th>Ürün / stok kodu</th><th>Adet</th><th>Fatura satır tutarı</th></tr></thead><tbody>${rows.map(product=>html`<tr><td><strong>${esc(product.title||t('Ürün adı aktarılmadı'))}</strong>${product.sku?html`<small>${esc(product.sku)}</small>`:''}</td><td>${Number.isSafeInteger(product.quantity)?esc(product.quantity):t('Teyit bekleniyor')}</td><td>${Number.isSafeInteger(product.invoiceCents)?money(product.invoiceCents):t('Aktarılmadı')}</td></tr>`).join('')}</tbody></table></div>`:html`<p class="form-help">Ürün ayrıntıları henüz doğrulanamadı; sonraki aktarımda tamamlanacak.</p>`}${returned?html`<p class="form-help">Fatura satır tutarı siparişe aittir; iade ödemesi değildir. Talep bildirimi, iadenin veya geri ödemenin tamamlandığı anlamına gelmez.</p>`:''}</section>`;
}

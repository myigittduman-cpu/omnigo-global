import {html,t} from './i18n.js';
import {esc,platform} from './ui.js';

export function marketplaceCard(item){
 return html`<article class="marketplace-setting" data-marketplace="${item.provider}"><header>${platform(item.name)}<span>${t(!item.selected?'Henüz eklenmedi':item.hidden?'Kart gizli':'Kart görünür')}</span></header>
 <p class="form-help">${t(item.configured?'API bilgileri kayıtlı.':'API bilgileri bekleniyor.')}</p>
 ${item.selected?html`<label class="marketplace-select"><input form="marketplaceBulk" name="stores" value="${item.provider}" type="checkbox" data-marketplace-select>Toplu işlem için seç</label>
 <button class="btn" type="button" data-marketplace-configure>Mağaza ayarları</button>`:html('<button class="btn primary" type="button" data-marketplace-action="add">Mağazayı ekle</button>')}
 <p class="form-error" role="alert"></p></article>`;
}
export function marketplaceSettingsView(items){
 return html`<div class="market-list-content"><p class="form-help">Kartı gizlediğinizde canlı aktarım devam eder.</p>
 <form id="marketplaceBulk" class="form-grid marketplace-bulk"><div class="marketplace-bulk-selection"><label><input type="checkbox" name="all" data-marketplace-all>Ekli mağazaların tümünü seç</label><small data-bulk-summary role="status">Henüz mağaza seçilmedi.</small></div><label class="marketplace-bulk-action">Toplu işlem<select name="action" required><option value="">İşlem seçin</option><option value="show">Seçilenleri göster</option><option value="hide">Seçilenleri gizle</option></select></label><p class="form-error full-width" role="alert"></p></form>
 <div class="marketplace-settings-list">${items.map(marketplaceCard).join('')}</div></div><footer class="market-list-footer"><button class="btn primary marketplace-bulk-save" type="submit" form="marketplaceBulk" disabled>Seçilenleri kaydet</button></footer>`;
}

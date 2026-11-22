import {turkeyCities} from './turkey-cities.js';
import {html,t} from './i18n.js';
import {esc} from './ui.js';
export function customerCitySettings(cities){
 return html`<details class="permission-sections"><summary>Teslimat şehirleri · ${cities.length} / 81 il seçili</summary><div class="permission-city-content"><p class="form-help">Bu müşteride kullanılacak illeri seçin. Seçimler şehir filtrelerine ve Şehir Dışı Teslimatlar sayfasına yansır. Şehri kaldırmak geçmiş kayıtları silmez veya durum takibini durdurmaz.</p><label>Şehir ara<input autocomplete="off" results="0" type="search" data-city-settings-search placeholder="İl adı yazın"></label><div class="permission-city-tools"><button class="btn" type="button" data-city-settings-all>Tüm illeri seç</button><button class="btn" type="button" data-city-settings-none>Seçimi temizle</button></div><fieldset class="permission-city-grid"><legend>${t('Kullanılacak iller')}</legend>${turkeyCities.map(city=>html`<label data-city-setting-row="${esc(city)}"><input type="checkbox" data-city-setting value="${esc(city)}" ${cities.includes(city)?'checked':''}>${esc(city)}</label>`).join('')}</fieldset></div></details>`;
}

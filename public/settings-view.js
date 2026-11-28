import {usernameSettings} from './account-username.js';
import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
import {esc} from './ui.js';
import {passwordChangeView} from './password-change.js';
import {sessionPreferenceView} from './session-preferences.js';
import {settingsActivityView} from './settings-activity.js';
import {shipmentLayoutFor,shipmentLayouts} from './shipment-layout.js';
import {themeFor,themeLabels} from './user-preferences.js';
import {menuStyles,menuStyleFor} from './menu-appearance.js';
export const settingsSections={account:['Hesap','Güvenlik, gizlilik ve hesap işlemleri'],sessions:['Açık oturumlar','Cihazlar ve oturum güvenliği'],tables:['Tablo düzenleri','Mağazaya göre tablo görünümü'],theme:['Tema','Hesabınızın renk teması'],language:['Dil','Türkçe, İngilizce ve Almanca']};
export function settingsButton(){return i18nHtml('<button type="button" class="notification-bell settings-button" data-open-settings aria-label="Ayarlar" title="Ayarlar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 3-1 3-3 1-2 3 2 2-1 3 3 2 3-1 2 3 3-1 1-3 3-1 1-3-2-2 1-3-3-2-3 1-2-3Z"/><circle cx="12" cy="11" r="3"/></svg></button>');}
const select=(name,label,description,values,current)=>i18nHtml`<label class="settings-field"><span>${i18nT(label)}</span><select name="${name}" aria-describedby="settings-${name}-help">${Object.entries(values).map(([value,text])=>i18nHtml`<option value="${value}" ${value===current?'selected':''}>${name==='language'?text:i18nT(text)}</option>`).join('')}</select><small id="settings-${name}-help">${i18nT(description)}</small></label>`;
export function settingsContent(section,user,values,company,shipmentLabel=i18nT('Şehir Dışı Sevkiyatlar')){
 if(section==='tables'){
  if(!company)return i18nHtml`<h3>${esc(shipmentLabel)}</h3><p class="settings-note">Tablo düzenini değiştirmek için bu menüye erişiminiz olan bir mağaza seçin.</p>`;
  return i18nHtml`<form data-settings-shipment-layout><h3>${esc(shipmentLabel)}</h3><p class="settings-note">Mağaza · <strong>${esc(company.name)}</strong></p>${select('shipmentLayout','Tablo düzeni','Bu tercih yalnızca hesabınızda ve seçili mağazada geçerlidir. Canlı aktarım her iki düzende de devam eder.',shipmentLayouts,shipmentLayoutFor(values,company.id))}<div class="settings-actions"><button class="btn primary" type="submit">Kaydet</button></div></form>`;
 }
 if(section==='sessions'||section==='history'&&user.role==='admin')return settingsActivityView(section==='history'?'history':'sessions');
 if(section==='theme')return i18nHtml`<form data-theme-preference>
  ${select('theme','Tema seçimi','Seçim hesabınıza kaydedilir.',themeLabels,themeFor(values.theme))}
  ${select('menuStyle','Menü tasarımı','Üst menü özgün uygulama yazı tipini kullanır. Bu seçim yalnız menü vurgusunu değiştirir. Tema renginiz ve tablolarınız korunur.',menuStyles,menuStyleFor(values.menuStyle))}
  <div class="settings-actions"><button class="btn primary" type="submit">Kaydet</button></div>
 </form>`;
 if(section==='language')return i18nHtml`<form data-language><h3>Uygulama dili</h3>${select('language',i18nT('Dil seçimi'),i18nT('Tercihiniz yalnız sizin hesabınıza uygulanır. Müşteri, ürün ve sipariş bilgileri çevrilmez.'),{tr:'Türkçe',en:'English',de:'Deutsch'},values.language||'tr')}<p class="settings-note">Dili kaydettiğinizde ekran yeni dilde açılır. Kaydedilmemiş sevkiyat değişiklikleri varsa önce nasıl devam edeceğiniz sorulur.</p><div class="settings-actions"><button class="btn primary" type="submit">Kaydet</button></div></form>`;
 if(section==='account')return i18nHtml`<div class="settings-account-grid"><section class="settings-account-card"><div class="settings-identity"><strong>${esc(user.name)}</strong><span>${esc(user.email)}</span><small>${user.role==='client'?i18nT('Müşteri hesabı'):i18nT('Yönetici hesabı')}</small></div>${usernameSettings(user)}${sessionPreferenceView()}</section><section class="settings-account-card"><h3>Şifre değiştir</h3><div data-password-area>${passwordChangeView()}</div></section></div><div class="settings-account-links">${user.role==='admin'?i18nHtml('<a class="settings-account-link" href="/account-security.html"><strong>İki aşamalı doğrulama</strong><small>Girişlerde e-posta kodu kullanımını yönetin.</small><span aria-hidden="true">›</span></a>'):''}<a class="settings-account-link" href="/notification-settings.html"><strong>Bildirim ayarları</strong><small>Bu cihazın bildirim iznini ve durumunu görüntüleyin.</small><span aria-hidden="true">›</span></a><a class="settings-account-link" href="/privacy.html"><strong>Gizlilik</strong><small>Verilerin hangi amaçla işlendiğini inceleyin.</small><span aria-hidden="true">›</span></a><a class="settings-account-link" href="/account-deletion.html"><strong>Hesap silme başvurusu</strong><small>Hesap ve kişisel veri silme adımlarını görüntüleyin.</small><span aria-hidden="true">›</span></a></div>`;
 return '';
}

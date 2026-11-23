import {shippingScopeFields,bindShippingScope} from './shipping-scope.js';
import {t as i18nT,text as i18nText,html as i18nHtml,labelList} from './i18n.js';
import {esc,platform} from './ui.js';
import {setupProviders} from './customer-api-help.js';
export const setupSteps=labelList(['Rehber','Firma bilgileri','Kullanıcı hesabı','Pazaryerleri','API bağlantıları','Son kontrol']);
export const setupMarketStatus=market=>i18nT(!market.selected?'Ekle':market.configured?'Bağlı · Pazaryeri ayarları':'API bekleniyor');
const field=(label,name,value='',type='text',extra='')=>i18nHtml`<label>${i18nT(label)}<input name="${name}" value="${esc(value)}" type="${type}" ${extra}></label>`;
export function setupView(company,data,markets,{provider=null}={}){
 const p=data.profile;
 return i18nHtml`<div class="setup-layout"><nav class="setup-progress" aria-label="Kurulum adımları">${setupSteps.map((label,index)=>!provider||index>=4?i18nHtml`<span data-step-label="${index}"><b>${provider?index-3:index+1}</b>${label}</span>`:'').join('')}</nav><div class="setup-main">
 <p class="setup-customer">Seçili mağaza: <strong>${esc(company.name)}</strong></p>
 <section data-step="0" ${provider?'hidden':''}><span class="eyebrow">MÜŞTERİ KURULUM REHBERİ</span><h3>Mağazalarınızı adım adım bağlayın</h3><p>Bu rehber ilk kurulum ve eksik adımları tamamlamak içindir. Firma bilgilerini ve pazaryeri bağlantılarını hazırlayın, mağazanın hesap durumunu kontrol edin.</p><div class="setup-guide-grid"><article><h4>Hazır bulundurun</h4><p>Firma ve yetkili bilgileri ile pazaryeri API bilgileri.</p></article><article><h4>Ayarlar otomatik hazırlanır</h4><p>Bağlantılar yalnız bu müşteriye eşleştirilir. Sipariş, iptal ve teslim edilemedi durumlarının aktarımı kuyrukta başlar. Yönetici ve müşteri ekranları aynı müşteri kayıtlarını kullanır.</p></article><article><h4>API bilgisi henüz yoksa</h4><p>“Daha sonra ekle” ile devam edebilirsiniz. Her pazaryerinde kopyalanabilir bir destek talebi bulunur. Bağlanmamış mağaza hazır gösterilmez.</p></article><article><h4>Kurulumdan sonra</h4><p>Günlük değişiklikler için Firma ayarları veya Pazaryeri ayarları bölümünü kullanın. Rehberi yeniden tamamlamanız gerekmez.</p></article></div><p class="form-help">Her Kaydet işlemi onayınızla uygulanır. Kaydedilmemiş değişikliklerle adım değiştirir veya pencereyi kapatırsanız nasıl devam edeceğiniz sorulur.</p></section>
 <section data-step="1" hidden><h3>Firma bilgileri</h3><form data-setup-profile class="form-grid">${shippingScopeFields(p.shippingScope)}${field(i18nT('Firma adı'),'name',company.name,'text','required maxlength="150"')}${field(i18nT('Yetkili kişi'),'contactName',p.contactName,'text','maxlength="100"')}${field(i18nT('Telefon'),'phone',p.phone,'tel','maxlength="40"')}${field(i18nT('İletişim e-postası'),'email',p.email,'email','maxlength="200"')}${field(i18nT('Adres'),'address',p.address,'text','maxlength="500"')}<label>Yönetici notu<textarea name="notes" maxlength="3000" rows="3">${esc(p.notes)}</textarea></label></form><hr><h4>Mağaza logosu · isteğe bağlı</h4><form data-setup-logo class="form-grid"><label>Logo dosyası<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required></label><button type="submit" class="btn">Logoyu yükle</button><small class="form-help full-width">Öneri: şeffaf PNG, 512 × 512 piksel. PNG, JPEG veya WebP; en fazla 1 MB ve 2048 × 2048 piksel. Oranı korunur ve İş Ortağım’a yansır.</small></form></section>
 <section data-step="2" hidden><h3>Mağaza kullanıcı hesabı</h3><div data-setup-accounts>${accountsView(data.users)}</div>
  <p class="form-help">Mağaza kullanıcılarını Mağazalar → Kullanıcı Yetkileri bölümünden yönetin.</p>
 </section>
 <section data-step="3" hidden><h3>Çalışılan pazaryerlerini seçin</h3>
  <p>Pazaryeri eklemek için işaretleyin. API bağlantısı henüz kaydedilmemiş bir pazaryerini işaretini kaldırarak çıkarabilirsiniz. Bağlı pazaryerlerini Pazaryeri ayarlarından yönetin.</p>
  <form data-setup-markets class="form-grid setup-market-selection">${markets.map(item=>i18nHtml`<label>
   <input type="checkbox" name="market_${item.provider}" data-setup-market="${item.provider}" ${item.selected?'checked':''} ${item.selected&&item.configured?'disabled':''}>
   ${platform(item.name)}<small data-setup-market-status>${setupMarketStatus(item)}</small>
  </label>`).join('')}</form>
 </section>
 <section data-step="4" ${provider?'':'hidden'}><h3>Mağaza ve API bilgileri</h3>
  <p>${i18nT(provider?'API bilgilerini kaydettiğinizde bağlantı kontrolü sıraya alınır.':'API bilgilerini hazırlayın. Son kontrolde “Canlı Aktarımı Başlat” düğmesine basarak bağlantı kontrolünü sıraya alın.')}</p>
  <div data-setup-connections></div><button type="button" class="text-btn" data-defer-api>Daha sonra ekle ve son kontrole geç</button>
 </section>
 <section data-step="5" hidden><h3>Son kontrol</h3><div data-setup-review></div>
  <div class="setup-review-actions">
   <button type="button" class="btn" data-setup-refresh>Bağlantı durumlarını yenile</button>
   ${provider?'':i18nHtml('<button type="button" class="btn primary" data-setup-start>Canlı Aktarımı Başlat</button>')}
  </div>
 </section>
 <p class="form-error" data-setup-error role="alert"></p><p class="form-help" data-setup-status role="status"></p>
 <footer class="setup-footer"><button type="button" class="btn" data-setup-back>Geri</button><span data-setup-counter></span><button type="button" class="btn primary" data-setup-next>İlerle</button></footer></div></div>`;
}
export function accountsView(users){return users.filter(u=>Number(u.active)).map(u=>i18nHtml`<p class="setup-check">✓ ${esc(u.name)} · ${esc(u.email)}</p>`).join('')||i18nHtml('<p>Bu mağazada henüz aktif kullanıcı hesabı yok.</p>');}
export function connectionForm(provider,market,status,company,{deferred=false}={}){
 if(deferred&&market.configured)return i18nHtml`<details class="setup-connection" data-setup-provider="${provider.key}"><summary>${platform(provider.name)}<span data-connection-status>API kayıtlı</span></summary><p>Kayıtlı API bilgilerini Pazaryeri ayarlarından yönetebilirsiniz.</p><p data-provider-message role="status"></p></details>`;
 return i18nHtml`<details class="setup-connection" data-setup-provider="${provider.key}"><summary>${platform(provider.name)}<span data-connection-status>Durum yükleniyor…</span></summary><form class="form-grid" data-setup-store="${provider.key}">${field(i18nT('Mağaza adı'),'storeName',market.storeName,'text','required maxlength="150"')}<p class="form-error full-width" data-provider-error role="alert"></p><button class="btn" type="submit">Mağaza adını kaydet</button></form><form class="form-grid" data-setup-credentials="${provider.key}">${provider.fields.map(([name,label,type,max])=>field(label,name,type==='password'?'':status?.[name]||'',type,`required maxlength="${max}" autocomplete="new-password"${type==='number'?' min="1" step="1"':''}`)).join('')}<p class="form-help full-width">Satıcı paneline giriş şifrenizi kullanmayın. API anahtarları kaydedildiğinde sunucuda şifreli tutulur.</p><p class="form-error full-width" data-provider-error role="alert"></p><button type="submit" class="btn primary">${i18nT(deferred?'Aktarım için hazırla':'API bilgilerini kaydet ve aktarımı başlat')}</button></form><p data-provider-message role="status"></p><div class="setup-help template-actions"><button class="btn" type="button" data-template="access">API bilgisi için destek talebi</button><button class="btn" type="button" data-template="permissions">API yetkileri için destek talebi</button></div></details>`;
}
const inventoryLabels={marketplace:'Pazaryeri stoğu',store:'Mağaza stoğu',both:'Pazaryeri ve mağaza stoğu',horoz:'Horoz ortak stok'};
const inventoryStartLabels={scheduled:'Stok aktarımı başarılı API kontrolünü bekliyor.',active:'Canlı stok aktarımı açık.',manual:'Mağaza stoğu elle takip ediliyor.',disabled:'Stok Yönetimi bu müşteride kapalı.',waiting_connections:'Stok aktarımı için seçili bağlantıların tamamlanması bekleniyor.'};
const serviceLabels={questions:'Sorular',payouts:'Hakediş',requests:'İptal ve iade talepleri'};
const statusLabels={manual:'Manuel takip',ready:'Güncel',running:'Aktarım sürüyor',stale:'Güncel veri bekleniyor',retry:'Yeniden denenecek',error:'Kontrol gerekli',missing_queue:'Kuyruk eksik',not_configured:'Bağlantı bekleniyor',waiting:'İlk kontrol bekleniyor',probe:'İlk doğrulanmış kayıt bekleniyor',inactive:'Kapalı'};
export function reviewView(markets,health,users,{plan=null,configuration=null,started=null,setupRun=null}={}){
 const selected=markets.filter(m=>m.selected),active=users.some(u=>Number(u.active));
 const inventory=configuration?.inventory?.mode;
 return i18nHtml`<p>${active?i18nT('✓ Aktif mağaza hesabı hazır.'):i18nT('Mağaza hesabı bekleniyor.')}</p>
  ${inventory?i18nHtml`<p>Stok modeli · <strong>${i18nT(inventoryLabels[inventory])}</strong></p>`:''}
  ${started?i18nHtml`<p role="status">${esc(started.inventoryError||i18nT(inventoryStartLabels[started.inventory]||''))}</p>`:''}
  ${setupRun?i18nHtml`<p role="status">${i18nT(setupRun.state==='complete'?'Mağaza kurulumu tamamlandı.':'Mağaza kurulumu başladı. İlk canlı aktarımlar doğrulanıyor.')}</p>
   ${setupRun.waiting?.length?i18nHtml`<details><summary>Doğrulama bekleyen aktarımlar</summary><ul>${setupRun.waiting.map(reason=>i18nHtml`<li>${esc(reason)}</li>`).join('')}</ul></details>`:''}`:''}
  ${selected.length?i18nHtml`<ul class="setup-review-list">${selected.map(m=>{
    const item=health?.items.find(i=>i.channel===m.name),result=started?.markets?.[m.provider];
    const text=result?.state==='error'?result.message:plan?.has(m.provider)?i18nT('API bilgileri aktarım için hazır'):
      !m.configured?result?.message||i18nT('API bilgileri daha sonra eklenecek'):item?.message||result?.message||i18nT('Bağlantı durumu doğrulanamadı.');
    const services=Object.entries(item?.services??{}).filter(([key,source])=>serviceLabels[key]&&statusLabels[source.status]);
    return i18nHtml`<li><strong>${esc(m.name)}</strong><span>${esc(text)}</span><small>${esc(m.storeName||i18nT('Mağaza adı bekleniyor'))}</small>
     ${m.configured&&services.length?i18nHtml`<div class="setup-service-notes">${services.map(([key,source])=>i18nHtml`<small>${i18nT(serviceLabels[key])} · ${i18nT(statusLabels[source.status])}</small>`).join('')}</div>`:''}</li>`;
   }).join('')}</ul>`:i18nHtml('<p>Pazaryeri seçilmedi.</p>')}
  <p><a href="https://portal.omnigoglobal.com/" target="_blank" rel="noopener">Mağaza kullanıcı girişi</a></p>`;
}

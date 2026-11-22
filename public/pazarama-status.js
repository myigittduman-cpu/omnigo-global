import {t as i18nT,text as i18nText,html as i18nHtml} from './i18n.js';
export function pazaramaStatus(data) {
 if(!data.configured) return {heading:'Mevcut Pazarama API bilgilerini girin.',scheduled:false};
 const state=data.job?.state;
 const heading=state==='blocked'?i18nT('Pazarama erişimi doğrulanamadı.'):
  state==='failed'?i18nT('Satış aktarımı durdu. Son başarılı veriler korunuyor.'):
  data.job?.error?i18nT('Aktarım yeniden denenecek. Son başarılı veriler gösteriliyor.'):
  data.sync?.salesPublished?i18nT('Satış ve ürün aktarımı etkin.'):state==='running'?i18nT('İlk satış aktarımı sürüyor.'):i18nT('İlk satış aktarımı sırada.');
 return {heading,scheduled:state==='pending'};
}

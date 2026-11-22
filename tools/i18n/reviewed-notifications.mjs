import {edit} from './reviewed-edits.mjs';
await edit('notification-view.js',[
 ['html as i18nHtml}', 'html as i18nHtml,currentLanguage}'],
 ["esc(provider?.[2]||'Pazaryerinden')", "esc(currentLanguage()==='tr'?(provider?.[2]||'Pazaryerinden'):(provider?.[0]||i18nT('Pazaryeri')))"],
 ["?'Okunan destek bildirimi yok.'", "?i18nT('Okunan destek bildirimi yok.')"],
 ["?'Okunan bildirim yok.'", "?i18nT('Okunan bildirim yok.')"],
 ["read:'Okunan'", "read:i18nT('Okunan')"]
]);
await edit('install.js',[
 ["document.title = admin ? 'Omnigo Global Yönetici Paneli' : 'Omnigo Global Müşteri Portalı';", "document.title = i18nT(admin ? 'Omnigo Global Yönetici Paneli' : 'Omnigo Global Müşteri Portalı');"],
 ["button.textContent = 'Uygulamayı yükle';", "button.textContent = i18nT('Uygulamayı yükle');\nwindow.addEventListener('omnigo-language-change',()=>{button.textContent=i18nT('Uygulamayı yükle');document.title=i18nT(admin?'Omnigo Global Yönetici Paneli':'Omnigo Global Müşteri Portalı');});"],
 ["close.textContent = 'Tamam'", "close.textContent = i18nT('Tamam')"]
]);

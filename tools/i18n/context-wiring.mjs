import {edit} from './reviewed-edits.mjs';
await edit('settings.js',[
 ["${i18nT(label)}</strong>","${i18nT(label,id==='account'?'account':undefined)}</strong>"],
 ["i18nT(settingsSections[section][0])","i18nT(settingsSections[section][0],section==='account'?'account':undefined)"]
]);
await edit('views.js',[["esc(t.channel)","esc(t.channel==='Tüm kanallar'?i18nT(t.channel):t.channel)"]]);
await edit('payment-breakdown.js',[["${selected?esc(date(selected))+' için':i18nT('Bu ay için')} ödeme kaydı bulunmuyor.","${selected?i18nText`${esc(date(selected))} için ödeme kaydı bulunmuyor.`:i18nT('Bu ay için ödeme kaydı bulunmuyor.')}"]]);
await edit('mobile-notifications.js',[["config.status?.[current.kind]?.message","i18nT(config.status?.[current.kind]?.message)"],["error.message||i18nT(","i18nT(error.message)||i18nT("],["?'Bildirimleri kapat'","?i18nT('Bildirimleri kapat')"],["initializing?'Bildirim izni kontrol ediliyor…'","initializing?i18nT('Bildirim izni kontrol ediliyor…')"]]);

import {edit} from './reviewed-edits.mjs';
await edit('calendar-panel.js',[
 ["html as i18nHtml}","html as i18nHtml,currentLocale}"],
 ["new Intl.DateTimeFormat('tr-TR',","new Intl.DateTimeFormat(currentLocale(),"],
 ["i18nT('Sonraki yıl'):'Sonraki ay'","i18nT('Sonraki yıl'):i18nT('Sonraki ay')"],
 ["['Pt','Sa',i18nT('Ça'),'Pe','Cu','Ct','Pa'].map(day=>i18nHtml`<span>${day}</span>`)","['Pt','Sa','Ça','Pe','Cu','Ct','Pa'].map(day=>i18nHtml`<span>${i18nT(day)}</span>`)"],
 ["<span>${name}</span><input type=\"number\" data-date-time=\"${index}\" aria-label=\"${name}\"","<span>${i18nT(name)}</span><input type=\"number\" data-date-time=\"${index}\" aria-label=\"${i18nT(name)}\""],
 ["i18nT('Dönemi uygula'):'Tarihi uygula'","i18nT('Dönemi uygula'):i18nT('Tarihi uygula')"]
]);
await edit('shipment-filters.js',[
 ['${label}<select','${i18nT(label)}<select'],
 ['>${text}</option>','>${i18nT(text)}</option>']
]);
await edit('order-detail.js',[
 ['<dt>${label}</dt>','<dt>${i18nT(label)}</dt>'],
 ["esc(order.statusLabel)","esc(i18nT(order.statusLabel))"]
]);
await edit('payment-form.js',[
 ['<label>${label}<input','<label>${i18nT(label)}<input'],
 ['>${esc(label)}</option>','>${esc(i18nT(label))}</option>'],
 ["r.source||i18nT('Yönetici girişi')","r.source||'Yönetici girişi'"],
 ["toast('En fazla 40 kalem eklenebilir.')","toast(i18nT('En fazla 40 kalem eklenebilir.'))"]
]);

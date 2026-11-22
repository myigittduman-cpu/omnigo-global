import {edit} from './reviewed-edits.mjs';
// First three reviewed edits already applied; continue from the exact source check.
await edit('hepsiburada.js',[["'Satış, iptal ve gerçekleşmiş ücret iadeleri iki panelin aylık özetine otomatik yansır. İadeler siparişin ait olduğu aydan düşülür. Son satış kontrolü: '+stamp(sync.salesLastSuccessAt)","i18nText`Satış, iptal ve gerçekleşmiş ücret iadeleri iki panelin aylık özetine otomatik yansır. İadeler siparişin ait olduğu aydan düşülür. Son satış kontrolü: ${stamp(sync.salesLastSuccessAt)}`"]]);
await edit('marketplace-summary.js',[["+' Tarihler Türkiye saatine göredir; iptal ve iadeler sipariş gününde gösterilir.'","+i18nT(' Tarihler Türkiye saatine göredir; iptal ve iadeler sipariş gününde gösterilir.')"]]);
await edit('payment-records.js',[["p.status==='sent'?'Banka teyidi bekleniyor'","p.status==='sent'?i18nT('Banka teyidi bekleniyor')"]]);
await edit('report-chart.js',[["netCents==null?'Veri yok'","netCents==null?i18nT('Veri yok')"]]);
await edit('sales-panels.js',[["new Date(month+'-01T12:00:00').toLocaleDateString(currentLocale(),{month:'long',year:'numeric'})+' toplamı'","i18nText`${new Date(month+'-01T12:00:00').toLocaleDateString(currentLocale(),{month:'long',year:'numeric'})} toplamı`"]]);
await edit('sales-summary-chart.js',[["label+' günlük '+series.map(s=>s.label).join(', ')","i18nText`${label} günlük ${series.map(s=>s.label).join(', ')}`"]]);
await edit('views.js',[["channels.join(', ')+' için iade verileri henüz aktarılmadı.'","i18nText`${channels.join(', ')} için iade verileri henüz aktarılmadı.`"]]);
await edit('shipment-customer.js',[["export function shipmentCustomerType(row){","import {t as i18nT} from './i18n.js';\nexport function shipmentCustomerType(row){"],["[row.customerType||'',","[i18nT(row.customerType||''),"]]);
await edit('shipment-workbook.js',[["if(['invoiceAddress','customerType','taxNumber','taxOffice'].includes(key))return workbookText(shipmentInvoiceFields(item)[key]);","if(['invoiceAddress','customerType','taxNumber','taxOffice'].includes(key)){const value=shipmentInvoiceFields(item)[key];return workbookText(key==='customerType'?i18nT(value):value);}"]]);

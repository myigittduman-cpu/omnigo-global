import fs from 'node:fs/promises';
import {edit} from './reviewed-edits.mjs';
// Explicit allowlist of display formatters. Parsing, storage, filenames, calendar
// date keys and Turkish name/search collation are deliberately excluded.
const files=['channel-cards.js','connection-health.js','customer-actions.js','customer-management.js','delivery-fields.js','delivery-view.js','freshness.js','hepsiburada.js','inbox-conversation.js','inbox-panels.js','inbox.js','integrations.js','koctas-check.js','live.js','marketplace-connection.js','marketplace-summary.js','monthly-sales.js','n11.js','notifications.js','operation-notification.js','order-detail.js','orders-view.js','page-refresh.js','payment-details.js','pazarama-conversation.js','pazarama.js','report-pdf.js','report-view.js','returns-view.js','sales-analytics-view.js','sales-panels.js','sales-summary-chart.js','sales-trend-plot.js','shipment-copy.js','shipment-export.js','shipment-notification.js','shipment-operations.js','shipments-view.js','support.js','workspace-entry.js'];
const calls=[".toLocaleString('tr-TR'",".toLocaleDateString('tr-TR'",".toLocaleTimeString('tr-TR'","new Intl.NumberFormat('tr-TR'","new Intl.DateTimeFormat('tr-TR'"];
for(const file of files){
 const source=await fs.readFile('public/'+file,'utf8');
 const pairs=calls.filter(call=>source.includes(call)).map(call=>[call,call.replace("'tr-TR'",'currentLocale()')]);
 if(!pairs.length)throw new Error('No reviewed display formatter: '+file);
 pairs.push(["from './i18n.js';", "from './i18n.js';\nimport {currentLocale} from './i18n.js';"]);
 // Only replace the first i18n import to avoid duplicate bindings in label-map files.
 const first=source.indexOf("from './i18n.js';");
 pairs[pairs.length-1]=[source.slice(0,first+"from './i18n.js';".length),source.slice(0,first+"from './i18n.js';".length)+"\nimport {currentLocale} from './i18n.js';"];
 await edit(file,pairs);
}
await edit('notification-time.js',[
 ['html as i18nHtml}', 'html as i18nHtml,currentLocale}'],
 ["const formatter=new Intl.DateTimeFormat('tr-TR',", 'const formatter=()=>new Intl.DateTimeFormat(currentLocale(),'],
 ['formatter.format(date)','formatter().format(date)']
]);

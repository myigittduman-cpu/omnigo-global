import fs from 'node:fs/promises';
import {sha256} from '../operations/PortalFileTransfer.mjs';
const old='verification/localization-20261025',folder='verification/localization-native-20261025';
const previous=JSON.parse(await fs.readFile(old+'/release-result.json','utf8'));
const originalPlan=JSON.parse(await fs.readFile(old+'/release-plan.json','utf8'));
const targets=['public/native-language.js','public/locales/catalog.js','public/app.js','public/store-review.js','public/action-confirmation.js','public/order-alert-model.js','public/order-alerts.js','src/PushDevices.php','public/version.json'];
let alerts=await fs.readFile('public/order-alerts.js','utf8');
alerts=alerts.replace("presentation:{...orderNotificationPresentation(group),title:'Omnigo Global Yönetici Paneli'},",'presentation:desktopNotificationPresentation(group),');
await fs.writeFile('public/order-alerts.js',alerts);
await fs.writeFile('public/version.json',JSON.stringify({version:'0.9.154'})+'\n');
const files=[];
for(const remote of targets){const local=remote.startsWith('src/')?'php/'+remote:remote;const before=previous.files.find(f=>f.remote===remote);if(!before&&remote!=='public/native-language.js')throw new Error('Unknown baseline '+remote);files.push({local,remote,sha256:sha256(await fs.readFile(local)),beforeSha256:before?.sha256||null});}
await fs.mkdir(folder,{recursive:true});
await fs.writeFile(folder+'/release-plan.json',JSON.stringify({version:'0.9.154',files,protected:originalPlan.protected},null,2));
await fs.copyFile(old+'/deploy.mjs',folder+'/deploy.mjs');
console.log('9 dosyalık dil uyumluluk yayını hazır.');

import fs from 'node:fs/promises';
import path from 'node:path';
import assert from 'node:assert/strict';
import {sha256} from '../operations/PortalFileTransfer.mjs';
const folder='verification/localization-20261025',originals=new Map();
for(const filename of (await fs.readdir(folder+'/reviewed-edits')).sort()){
 const match=filename.match(/^(.+?)(?:\.\d+)?\.bak$/);if(match&&!originals.has('public/'+match[1]))originals.set('public/'+match[1],folder+'/reviewed-edits/'+filename);
}
for(const sub of ['before-label-metadata','before-codemod','before'])for(const filename of await fs.readdir(folder+'/'+sub))originals.set((filename.endsWith('.php')?'php/src/':'public/')+filename,folder+'/'+sub+'/'+filename);
for(const filename of await fs.readdir(folder+'/before-notifications'))originals.set('php/src/'+filename,folder+'/before-notifications/'+filename);
for(const file of ['public/version.json','public/sw.js']){
 const backup=folder+'/before/'+path.basename(file);
 if(!await fs.stat(backup).catch(()=>null))await fs.copyFile(file,backup);
 originals.set(file,backup);
}
await fs.writeFile('public/version.json','{"version":"0.9.153"}\n');
const sw=await fs.readFile('public/sw.js','utf8');await fs.writeFile('public/sw.js',sw.replace("const CACHE = 'omnigo-offline-v1';","const CACHE = 'omnigo-offline-i18n-v2';"));
const files=[];
for(const [local,backup]of originals){
 const content=await fs.readFile(local),before=await fs.readFile(backup);
 if(sha256(content)===sha256(before))continue;
 files.push({local,remote:local.replace(/^php\//,''),sha256:sha256(content),beforeSha256:sha256(before),backup});
}
for(const local of ['public/i18n.js','public/static-i18n.js','public/status-label.js','public/locales/catalog.js','php/src/NotificationLanguage.php','php/src/notification-translations.json'])files.push({local,remote:local.replace(/^php\//,''),sha256:sha256(await fs.readFile(local)),beforeSha256:null,backup:null});
assert.equal(new Set(files.map(f=>f.remote)).size,files.length);
const preference=files.find(f=>f.remote==='src/UserPreferences.php');assert(preference);files.push({...preference,remote:'store-review/runtime/src/UserPreferences.php'});
const protectedFiles=['src/Application.php','src/Security.php','src/SyncQueue.php','src/OrderTransfer.php','src/ShipmentRoutes.php','src/DeliveryRoutes.php','src/DeliveryMapper.php','src/ShipmentRepository.php','src/PttAvmOrderMapper.php','src/MarketplaceSalesSummary.php','src/CancellationHistory.php','public/shipments.css','public/delivery.css','public/workspace-presentation.css','public/workspace-sync.js'];
const existingProtected=[];for(const remote of protectedFiles){const local=remote.startsWith('src/')?'php/'+remote:remote;if(await fs.stat(local).catch(()=>null))existingProtected.push({remote,local,sha256:sha256(await fs.readFile(local))});}
await fs.writeFile(folder+'/release-plan.json',JSON.stringify({version:'0.9.153',preparedAt:new Date().toISOString(),files,protected:existingProtected},null,2));
console.log(JSON.stringify({version:'0.9.153',files:files.length,protected:existingProtected.length,newFiles:files.filter(f=>!f.beforeSha256).map(f=>f.remote)}));

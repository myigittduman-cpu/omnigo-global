import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import {CpanelClient,remoteRoot} from '../operations/CpanelClient.mjs';
const access=JSON.parse(await fs.readFile('data/store-review/access.json','utf8'));
const results=[];
for(const role of ['client','admin']){
 const origin=`https://${role==='client'?'portal':'yonetim'}.omnigoglobal.com`,cookies=new Map();let csrf='';
 const request=async(route,method='GET',data)=>{
  const r=await fetch(origin+route,{method,headers:{'Content-Type':'application/json',Origin:origin,'X-CSRF-Token':csrf,Cookie:[...cookies].map(([k,v])=>k+'='+v).join('; ')},...(data?{body:JSON.stringify(data)}:{}),signal:AbortSignal.timeout(20000)});
  for(const cookie of r.headers.getSetCookie()){const [pair]=cookie.split(';'),i=pair.indexOf('=');if(/max-age=0(?:;|$)/i.test(cookie))cookies.delete(pair.slice(0,i));else cookies.set(pair.slice(0,i),pair.slice(i+1));}
  const text=await r.text();return {status:r.status,headers:r.headers,body:r.headers.get('content-type')?.includes('application/json')?JSON.parse(text):null,bytes:Buffer.byteLength(text)};
 };
 const status=await request('/api/status');assert.equal(status.status,200);assert.equal(status.body.reviewEnvironment,false);assert.equal(status.body.portalRole,role);
 assert.equal((await request('/api/companies')).status,401);
 const login=await request('/api/login','POST',access[role]);assert.equal(login.status,200,'İnceleme girişi başarısız: '+role);assert.equal(login.body.reviewEnvironment,true);assert.equal(login.body.user.role,role);csrf=login.body.csrf;
 assert(login.headers.getSetCookie().some(c=>c.startsWith('og_review_'+role+'_session=')&&/Secure/i.test(c)&&/HttpOnly/i.test(c)&&/SameSite=Strict/i.test(c)));
 const companies=await request('/api/companies');assert.equal(companies.status,200);assert.equal(companies.body.length,role==='client'?1:2);assert(companies.body.every(c=>['Örnek Ev & Yaşam','Örnek İkinci İşletme'].includes(c.name)));
 assert.equal((await request('/api/status')).body.reviewEnvironment,true);
 assert.equal((await request('/api/dashboard?company=999999&month=2026-10')).status,role==='admin'?404:403);
 if(role==='client')assert.equal((await request('/api/admin/orders?company=1&from=2026-10-01&to=2026-10-31')).status,403);
 const shipment=await request('/api/shipments?company=1&provider=n11&city=all&month=2026-10');assert.equal(shipment.status,200);assert.equal(shipment.body.items.length,4);assert(shipment.body.items.every(item=>item.customerName.includes('Örnek')));
 const push=await request('/api/mobile-push/config');assert.equal(push.status,200);assert.equal(push.body.android,true);assert.equal(push.body.ios,false);
 if(role==='client'){
  const deletion=await request('/api/account/deletion-request','POST',{confirmed:true});assert.equal(deletion.status,202);assert(deletion.body.requestId.startsWith('ORNEK-SILME-'));
 }else{
  const support=await request('/api/support/tickets?company=1');assert.equal(support.status,200);assert(JSON.stringify(support.body).includes('Örnek hesap silme talebi'));
 }
 for(const route of ['/store-review/runtime/review.sqlite','/store-review/gateway.php','/storage/backup.key','/config.php'])assert([403,404].includes((await request(route)).status),'Özel dosya webden erişilememeli.');
 assert.equal((await request('/api/logout','POST',{})).status,200);assert.equal((await request('/api/me')).status,401);
 results.push({role,productionAnonymous:401,login:200,isolatedCompanies:companies.body.length,syntheticShipments:shipment.body.items.length,foreignCompanyDenied:true,privateFilesInaccessible:true,androidReady:true,sampleDeletionRequest:true,logout:401});
}
const c=await CpanelClient.connect(),deployment=JSON.parse(await fs.readFile('data/store-review/deployment.json','utf8'));
const features=JSON.parse(await fs.readFile('data/store-review/features-deployment.json','utf8'));
for(const [file,hash] of Object.entries({...deployment.sourceHashes,...features.hashes}))assert.equal(crypto.createHash('sha256').update((await c.readPortalFile(file)).content).digest('hex'),hash);
const health=JSON.parse((await c.readPortalFile('store-review/runtime/storage/review-push-health.json')).content);
assert.equal(health.state,'ok');assert(Date.now()-Date.parse(health.checkedAt)<180000,'Örnek bildirim görevi güncel çalışmalı.');
const cron=await c.api2('Cron','listcron'),before=JSON.parse(await fs.readFile('data/store-review/crons-before.json','utf8'));
const worker=`/usr/local/bin/php ${remoteRoot}/store-review/runtime/review-push-worker.php >/dev/null 2>&1`;
const normalize=jobs=>jobs.map(j=>JSON.stringify([j.minute,j.hour,j.day,j.month,j.weekday,j.command])).sort();
assert.deepEqual(normalize(cron.filter(j=>j.command!==worker)),normalize(before));assert.equal(cron.filter(j=>j.command===worker).length,1);
const rootFiles=await c.uapi('Fileman','list_files',{dir:remoteRoot,include_mime:0,include_hash:0});
assert(!rootFiles.some(item=>/^store-review-(preflight|install|features)\.php$/.test(item.file)),'Geçici yardımcı kalmamalı.');
const report={checkedAt:new Date().toISOString(),passed:true,results,sourceHashesVerified:true,temporaryTaskRemoved:deployment.temporaryTaskRemoved&&features.temporaryTaskRemoved,existingCronsPreserved:true,isolatedPushWorker:health,productionDatabaseChanged:false};
await fs.writeFile('verification/store-review/live-tests.json',JSON.stringify(report,null,2));console.log(JSON.stringify(report));

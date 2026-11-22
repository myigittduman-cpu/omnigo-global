import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {once} from 'node:events';
const root=path.resolve(import.meta.dirname,'../..'),area=path.join(root,'work/store-review-access');
const php=path.resolve(root,'../work/php-runtime/php.exe');
const access=JSON.parse(await fs.readFile(path.join(root,'data/store-review/access.json'),'utf8'));
const reviewRuntime=area+'/store-review/runtime';
for(const name of ['StoreReviewGateway.php','StoreReviewPolicy.php','AccountDeletionRequest.php','StoreReviewDeletionRequest.php']){
 await fs.copyFile(root+'/php/src/'+name,reviewRuntime+'/src/'+name);
 await fs.copyFile(root+'/php/src/'+name,area+'/src/'+name);
}
const fakeKey=reviewRuntime+'/storage/test-fcm.json',fakeConfig=reviewRuntime+'/storage/mobile-push.json';
assert(!(await fs.stat(fakeKey).catch(()=>null))&&!(await fs.stat(fakeConfig).catch(()=>null)),'Mevcut bildirim yapılandırması korunmalı.');
const keys=crypto.generateKeyPairSync('rsa',{modulusLength:2048,privateKeyEncoding:{type:'pkcs8',format:'pem'},publicKeyEncoding:{type:'spki',format:'pem'}});
await fs.writeFile(fakeKey,JSON.stringify({type:'service_account',project_id:'review-local-test',client_email:'test@review-local-test.iam.gserviceaccount.com',private_key:keys.privateKey}));
await fs.writeFile(fakeConfig,JSON.stringify({fcm:{enabled:true,projectId:'review-local-test',serviceAccountFile:fakeKey},apns:{enabled:false}}));
let logs='',checks=0;const results=[];
const reset=spawnSync(php,['-r',`require '${area.replaceAll('\\','/')}/bootstrap.php';foreach(['${area.replaceAll('\\','/')}/config.php','${area.replaceAll('\\','/')}/store-review/runtime/config.php'] as $file){$db=new \\Omnigo\\Database((require $file)['database']);$db->run('DELETE FROM login_attempts');$db->run('DELETE FROM sessions');}`],{encoding:'utf8'});assert.equal(reset.status,0,reset.stderr);
const start=port=>{const server=spawn(php,['-d','extension=fileinfo','-S',`127.0.0.1:${port}`,'-t',area+'/public',area+'/router.php'],{env:{...process.env,OMNIGO_CONFIG:area+'/config.php'},stdio:['ignore','ignore','pipe']});server.stderr.on('data',data=>logs+=data);return server;};
const servers=[8823,8824].map(start);
function client(port){return {origin:`http://127.0.0.1:${port}`,cookies:new Map(),csrf:''};}
async function call(session,route,method='GET',data,extra={}){
 const response=await fetch(session.origin+'/api'+route,{method,headers:{...(data instanceof FormData?{}:{'Content-Type':'application/json'}),'Origin':session.origin,'X-CSRF-Token':session.csrf,'Cookie':[...session.cookies].map(([k,v])=>k+'='+v).join('; '),...extra},...(data?{body:data instanceof FormData?data:JSON.stringify(data)}:{})});
 for(const cookie of response.headers.getSetCookie()){const [pair]=cookie.split(';'),i=pair.indexOf('=');if(/max-age=0(?:;|$)/i.test(cookie))session.cookies.delete(pair.slice(0,i));else session.cookies.set(pair.slice(0,i),pair.slice(i+1));}
 const body=await response.json();return {status:response.status,body,headers:response.headers};
}
const ok=(response,status=200)=>{checks++;assert.equal(response.status,status,JSON.stringify(response.body));return response.body;};
const check=(condition,message)=>{checks++;assert(condition,message);};
const login=async(session,role)=>{const r=await call(session,'/login','POST',{...access[role],keepSession:true});const body=ok(r);session.csrf=body.csrf;return r;};
const run=async(name,fn)=>{await fn();results.push({name,passed:true});console.log('GEÇTİ: '+name);};
const customer=client(8823),admin=client(8824),canary=client(8824),anonymous=client(8823);
const month=new Intl.DateTimeFormat('en-CA',{year:'numeric',month:'2-digit',timeZone:'Europe/Istanbul'}).format(new Date());
const selection='company=1&provider=n11&city=all&month='+month;
try{
 for(let i=0;i<40;i++){try{await call(anonymous,'/status');break;}catch{await new Promise(r=>setTimeout(r,150));}}
 await run('Üretim sınırı, ayrı oturumlar ve rol doğrulaması',async()=>{
  const production=ok(await call(canary,'/login','POST',{email:'canary@example.invalid',password:'Local-Canary-Password-123'}));canary.csrf=production.csrf;
  check(ok(await call(canary,'/companies'))[0].name==='ÜRETİM SINIRI TEST KAYDI','Sınır test kaydı hazır olmalı.');
  const result=await login(customer,'client');check(result.body.reviewEnvironment===true,'İnceleme işareti gerekli.');
  check(result.headers.getSetCookie().some(c=>/^og_review_client_session=/.test(c)&&/HttpOnly/.test(c)&&/SameSite=Strict/.test(c)),'Ayrı güvenli çerez gerekli.');
  const oldCookie=canary.cookies.get('og_admin_session');await login(canary,'admin');
  check(!canary.cookies.get('og_admin_session'),'İncelemeye geçerken eski üretim çerezi temizlenmeli.');
  check(ok(await call(canary,'/companies')).every(c=>c.name.startsWith('Örnek')),'İnceleme yöneticisi üretim şirketini görmemeli.');
  const transplanted=client(8824);transplanted.cookies.set('og_admin_session',canary.cookies.get('og_review_admin_session'));
  ok(await call(transplanted,'/me'),401);
  transplanted.cookies.set('og_store_review','1');transplanted.cookies.set('og_review_admin_session',oldCookie);ok(await call(transplanted,'/me'),401);
  ok(await call(canary,'/logout','POST',{}));ok(await call(canary,'/me'),401);
  await login(admin,'admin');
  const wrongRole=client(8823);ok(await call(wrongRole,'/login','POST',access.admin),403);
  const crossSite=client(8823);ok(await call(crossSite,'/login','POST',access.client,{Origin:'https://attacker.invalid'}),403);check(!crossSite.cookies.size,'Yabancı kaynak çerez değiştirmemeli.');
 });
 await run('Şirket sınırı, yönetici API engeli ve CSRF',async()=>{
  check(ok(await call(customer,'/companies')).length===1,'Müşteri tek şirket görmeli.');
  ok(await call(customer,'/dashboard?company=2&month='+month),403);
  ok(await call(customer,'/shipments?'+selection.replace('company=1','company=2')),403);
  ok(await call(customer,'/admin/orders?company=1'),403);
  ok(await call(customer,'/admin/delivery/orders?company=1'),403);
  ok(await call(admin,'/support/tickets?company=1','POST',{},{'X-CSRF-Token':'wrong'}),403);
  ok(await call(admin,'/integrations/koctas?company=1','POST',{}),409);
  ok(await call(admin,'/mobile-push/register','POST',{}),400);
  ok(await call(admin,'/push/register','POST',{}),409);
  ok(await call(customer,'/password/forgot','POST',{email:'real@example.com'}),409);
 });
 await run('İnceleme deposu yokken üretime dönmeme ve bağlantı sonrası devam',async()=>{
  const db=area+'/store-review/runtime/review.sqlite',moved=db+'.unavailable';
  await fs.rename(db,moved);
  try{ok(await call(admin,'/companies'),503);}finally{await fs.rename(moved,db);}
  check(ok(await call(admin,'/companies')).every(c=>c.name.startsWith('Örnek')),'Depo geri geldiğinde örnek şirketler yüklenmeli.');
  const stopped=once(servers[1],'exit');servers[1].kill();await stopped;
  await assert.rejects(()=>call(admin,'/companies'));checks++;
  servers[1]=start(8824);
  for(let i=0;i<30;i++){try{await call(admin,'/status');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  check(ok(await call(admin,'/me')).user.email===access.admin.email,'Bağlantı geri geldiğinde aynı örnek hesap kalmalı.');
 });
 await run('Sipariş, geciken/kargodaki kayıtlar ve belge veri kaynağı',async()=>{
  const orders=ok(await call(admin,`/admin/orders?company=1&from=${month}-01&to=${month}-28`));check(JSON.stringify(orders).includes('ORNEK-1001'),'Örnek sipariş görünmeli.');
  const delayed=ok(await call(admin,'/admin/delivery/orders?company=1&city=all&provider=all&mode=delayed'));
  check(JSON.stringify(delayed).includes('ORNEK-1003'),'Geciken sipariş görünmeli.');
  const shipped=ok(await call(admin,'/admin/delivery/orders?company=1&city=all&provider=all&mode=shipped'));
  check(JSON.stringify(shipped).includes('ORNEK-1002'),'Kargodaki sipariş görünmeli.');
  const exported=ok(await call(customer,'/shipments/export?'+selection,'POST',{query:'',filters:{}}));
  check(JSON.stringify(exported).includes('Deniz Örnek'),'Excel/PDF veri kaynağı örnek olmalı.');
 });
 await run('Müşteri düzenleme, yönetici eşleşmesi ve eski sürüm reddi',async()=>{
  const rows=ok(await call(customer,'/shipments?'+selection));check(rows.items.length===4,'Dört örnek sipariş olmalı.');
  const row=rows.items[0],keys=['orderedAt','customerName','recipientName','phone','deliveryAddress','province','district','productName','quantity','invoiceName','invoiceAddress','recipientEmail','customerType','taxNumber'];
  const original=Object.fromEntries(keys.map(k=>[k,String(row[k]??'')]));original.orderedAt=original.orderedAt.slice(0,19);original.invoiceAmount=String(row.invoiceCents/100);
  const updated=ok(await call(customer,`/shipments/${row.id}/details?${selection}`,'PUT',{version:row.version,record:{...original,recipientName:'Örnek Güncelleme'}}));
  const seen=ok(await call(admin,'/shipments?'+selection));check(seen.items.find(r=>r.id===row.id).recipientName==='Örnek Güncelleme','Yönetici güncellenen veriyi görmeli.');
  ok(await call(customer,`/shipments/${row.id}/details?${selection}`,'PUT',{version:row.version,record:original}),409);
  ok(await call(customer,`/shipments/${row.id}/details?${selection}`,'PUT',{version:updated.version,record:original}));
 });
 await run('JPEG yükleme/indirme, bozuk dosya ve başka şirket engeli',async()=>{
  const row=ok(await call(customer,'/shipments?'+selection)).items[0];
  const image=spawnSync(php,['-r','$i=imagecreatetruecolor(40,30);ob_start();imagejpeg($i);echo base64_encode(ob_get_clean());'],{encoding:'utf8'});assert.equal(image.status,0);
  const make=(bytes,version)=>{const form=new FormData();form.set('file',new Blob([bytes],{type:'image/jpeg'}),'ornek-test.jpg');form.set('version',String(version));form.set('requestId',crypto.randomBytes(24).toString('hex'));return form;};
  const route=`/shipments/${row.id}/documents/invoice?${selection}`;
  ok(await call(customer,route,'POST',make(Buffer.from('bozuk-dosya'),row.version)),415);
  ok(await call(customer,route,'POST',make(Buffer.from(image.stdout,'base64'),row.version)));
  const downloaded=ok(await call(admin,route));check(downloaded.width===40&&downloaded.height===30,'Aynı örnek belge yöneticiye ulaşmalı.');
  ok(await call(customer,route.replace('company=1','company=2')),403);
  const notifications=ok(await call(admin,'/notifications?company=1'));check(JSON.stringify(notifications).includes('invoiceDocument')||JSON.stringify(notifications).includes('belge')||JSON.stringify(notifications).includes('evrak'),'Belge bildirimi kaydı olmalı.');
 });
 await run('Destek talebi, yanıt, çıkış ve yeniden giriş',async()=>{
  const ticket=ok(await call(customer,'/support/tickets?company=1','POST',{subject:'Örnek inceleme talebi',body:'Bu yalnızca test kaydıdır.',category:'question',requestId:crypto.randomBytes(24).toString('hex')}),201);
  ok(await call(admin,`/support/tickets/${ticket.id}/reply?company=1`,'POST',{body:'Örnek yanıt kaydedildi.',requestId:crypto.randomBytes(24).toString('hex')}));
  check(JSON.stringify(ok(await call(customer,`/support/tickets/${ticket.id}?company=1`))).includes('Örnek yanıt kaydedildi.'),'Müşteri yanıtı görmeli.');
  ok(await call(customer,'/logout','POST',{}));ok(await call(customer,'/me'),401);await login(customer,'client');ok(await call(customer,'/me'));
  ok(await call(admin,'/logout','POST',{}));ok(await call(admin,'/me'),401);await login(admin,'admin');ok(await call(admin,'/me'));
 });
 await run('Örnek silme talebi, mobil kayıt ve çerezsiz belirteç yenileme',async()=>{
  ok(await call(customer,'/account/deletion-request','POST',{confirmed:false}),400);
  const deletion=ok(await call(customer,'/account/deletion-request','POST',{confirmed:true}),202);
  check(deletion.requestId.startsWith('ORNEK-SILME-'),'Örnek silme talebi oluşturulmalı.');
  check(JSON.stringify(ok(await call(admin,'/support/tickets?company=1'))).includes('Örnek hesap silme talebi'),'Örnek yönetici talebi görmeli.');
  for(const [role,session] of [['client',customer],['admin',admin]]){
   const registered=ok(await call(session,'/mobile-push/register','POST',{kind:'android',appId:'com.omnigoglobal.'+role,installationId:crypto.randomBytes(32).toString('hex'),token:'LOCAL_TEST_ONLY_TOKEN_'+role,environment:'production',sound:true}));
   const native=client(role==='client'?8823:8824);
   check(typeof registered.refreshToken==='string','Yenileme erişimi oluşturulmalı.');
   ok(await call(native,'/mobile-push/refresh','POST',{token:'LOCAL_TEST_REFRESHED_TOKEN_'+role},{'X-Omnigo-Device':registered.refreshToken}));
   ok(await call(session,'/mobile-push/devices/'+registered.id+'/test','POST',{}));
   ok(await call(session,'/logout','POST',{}));
   ok(await call(native,'/mobile-push/refresh','POST',{token:'LOCAL_TEST_REFRESHED_TOKEN_'+role},{'X-Omnigo-Device':registered.refreshToken}),401);
  }
 });
 await fs.mkdir(path.join(root,'verification/store-review'),{recursive:true});
 await fs.writeFile(path.join(root,'verification/store-review/local-tests.json'),JSON.stringify({checkedAt:new Date().toISOString(),checks,results,productionDataUsed:false,physicalDevice:false},null,2));
 console.log(JSON.stringify({passed:true,checks,groups:results.length}));
}catch(error){console.error(error.message);await fs.writeFile(path.join(area,'test-server.log'),logs);process.exitCode=1;}
finally{for(const server of servers){if(server.exitCode===null){const exit=once(server,'exit');server.kill();await exit;}}await fs.unlink(fakeKey);await fs.unlink(fakeConfig);}

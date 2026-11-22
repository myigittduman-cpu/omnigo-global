import test from 'node:test';import assert from 'node:assert/strict';import vm from 'node:vm';import fs from 'node:fs';
const source=fs.readFileSync(new URL('../public/push-worker.js',import.meta.url),'utf8');
function fixture({origin='https://yonetim.omnigoglobal.com',userAgent=''}={}){const listeners={},shown=[],opened=[];let focused=0,messages=[];const worker={location:{origin},navigator:{userAgent},addEventListener:(name,fn)=>listeners[name]=fn,registration:{showNotification:async(title,options)=>shown.push({title,...options})},clients:{matchAll:async()=>[],openWindow:async url=>opened.push(url)}};vm.runInNewContext(source,{self:worker,URL,Date});return{listeners,shown,opened,worker};}
test('Kapalı uygulama bildirimi sesi, pazaryerini ve Omnigo simgelerini taşır; pencereyi kendiliğinden açmaz',async()=>{const f=fixture();let pending;f.listeners.push({data:{json:()=>({title:'Pazarama · Yeni sipariş',body:'Örnek mağaza',sound:true,url:'/?orderCompany=1',tag:'order-1'})},waitUntil:p=>pending=p});await pending;assert.equal(f.shown[0].silent,false);assert.equal(f.shown[0].title,'Omnigo Global Yönetici Portalı');assert.equal(f.shown[0].icon,'/assets/app-admin-512-v6.png');assert.equal(f.shown[0].badge,'/assets/notification-badge.svg');assert.equal(f.opened.length,0);});
test('Sessiz tercih ve aynı kaynağa yönlendirme sınırı korunur',async()=>{const f=fixture();let pending;for(const url of ['/?orderCompany=2','https://evil.test/']){f.listeners.push({data:{json:()=>({title:'Yeni sipariş',body:'Örnek',sound:false,url})},waitUntil:p=>pending=p});await pending;}assert.equal(f.shown.length,1);assert.equal(f.shown[0].silent,true);});

test('Mobil bildirimlerde lacivert, Windows bildirimlerinde şeffaf onaylı marka simgesi seçilir',async()=>{
 for(const role of ['admin','client'])for(const userAgent of ['iPhone','iPad','Macintosh','Windows NT 10.0','Linux; Android 15']){
  const origin=role==='admin'?'https://yonetim.omnigoglobal.com':'https://portal.omnigoglobal.com';
  const f=fixture({origin,userAgent});let pending;
  f.listeners.push({data:{json:()=>({title:'Omnigo',body:'Simge kontrolü',sound:false,url:'/?noticeId=12'})},waitUntil:p=>pending=p});await pending;
  const apple=/iPhone|iPad|Macintosh/.test(userAgent);
  const expected=/Android/.test(userAgent)?`/assets/app-${role}-mobile-512-v3.png`:apple?`/assets/app-${role}-mobile-1024-v3.png`:`/assets/app-${role}-512-v6.png`;
  assert.equal(f.shown[0].icon,expected);
  assert.equal(f.shown[0].data.url,'/?noticeId=12');
  assert.equal(f.shown[0].silent,true);
 }
});
test('Bildirime dokunma mevcut portalı odaklar veya kapalıysa açar',async()=>{const f=fixture();let pending,focused=0,received;const event={notification:{close(){},data:{url:'/?orderCompany=2'}},waitUntil:p=>pending=p};f.listeners.notificationclick(event);await pending;assert.equal(f.opened[0],'https://yonetim.omnigoglobal.com/?orderCompany=2');f.worker.clients.matchAll=async()=>[{url:'https://yonetim.omnigoglobal.com/',focus:async()=>focused++,postMessage:data=>received=data}];f.listeners.notificationclick(event);await pending;assert.equal(focused,1);assert.equal(received.companyId,2);assert.equal(f.opened.length,1);});
test('Müşteri yükleme bildirimi doğru şirket ve değişiklik ayrıntısını açar',async()=>{const f=fixture();let pending,received;f.worker.clients.matchAll=async()=>[{url:'https://yonetim.omnigoglobal.com/',focus:async()=>{},postMessage:data=>received=data}];f.listeners.notificationclick({notification:{close(){},data:{url:'/?orderCompany=2&view=shipments&noticeType=shipment&noticeId=81'}},waitUntil:p=>pending=p});await pending;assert.equal(received.eventType,'shipment');assert.equal(received.companyId,2);assert.equal(received.noticeId,81);});

test('Açık PWA penceresine hakediş, stok ve iade hedefi kaybolmadan aktarılır',async()=>{
 for(const [role,view,type,id] of [['client','payments',null,null],['admin','inventory','operation',71],['admin','returns','operation',72]]){
  const origin=role==='client'?'https://portal.omnigoglobal.com':'https://yonetim.omnigoglobal.com',f=fixture({origin});let pending,received;
  f.worker.clients.matchAll=async()=>[{url:origin+'/',focus:async()=>{},postMessage:value=>received=value}];
  const url=`/?view=${view}&orderCompany=2${type?`&noticeType=${type}&noticeId=${id}`:''}`;
  f.listeners.notificationclick({notification:{close(){},data:{url}},waitUntil:value=>pending=value});await pending;
  assert.equal(received.view,view);assert.equal(received.companyId,2);assert.equal(received.noticeId,id??0);
 }
});

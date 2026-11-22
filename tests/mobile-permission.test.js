import test from 'node:test';
import assert from 'node:assert/strict';
const tick=()=>new Promise(resolve=>setImmediate(resolve));
let sequence=0;
class Element extends EventTarget{
 constructor(tag,nodes){super();this.tagName=tag;this.nodes=nodes;this.children=[];this.isConnected=false;this.hidden=false;this.attrs={};this.dataset={};}
 setAttribute(k,v){this.attrs[k]=v;}
 append(...children){for(const child of children){this.children.push(child);child.isConnected=true;this.nodes.push(child);}}
 remove(){this.isConnected=false;}
 querySelector(selector){return this.controls?.[selector]||null;}
 set innerHTML(value){this.html=value;this.controls={};for(const selector of ['[data-permission-allow]','[data-permission-decline]'])this.controls[selector]=new Element('button',this.nodes);}
 showModal(){this.open=true;}
 close(){this.open=false;this.dispatchEvent(new Event('close'));}
}
async function setup(options={}){
 const previous={window:globalThis.window,document:globalThis.document,localStorage:globalThis.localStorage,fetch:globalThis.fetch,matchMedia:globalThis.matchMedia};
 globalThis.matchMedia=()=>({matches:false});
 const calls=[],requests=[],nodes=[],storage=options.storage||new Map();
 const current={kind:options.kind||'android',configured:true,appId:'com.omnigoglobal.'+(options.role||'admin'),installationId:String(++sequence).padStart(64,'0'),enabled:false,permission:false,userId:0,id:0,sound:true,...options.current};
 globalThis.localStorage={getItem:key=>storage.get(key)||null,setItem:(key,value)=>storage.set(key,value)};
 const body=new Element('body',nodes),head=new Element('head',nodes);
 globalThis.document={addEventListener(){},body,head,documentElement:{dataset:{}},createElement:tag=>new Element(tag,nodes),getElementById:id=>nodes.find(n=>n.id===id&&n.isConnected)||null};
 const window=globalThis.window=new EventTarget();
 window.__OMNIGO_MOBILE_PUSH__=async(command,data)=>{
  calls.push({command,data});
  if(command==='push_status')return {...current};
  if(command==='push_enable'){
   if(options.deny)throw new Error('Bildirim izni verilmedi.');
   current.permission=true;
   if(options.tokenFailure)throw new Error('Bildirim belirteci alınamadı.');
   return {...current,token:'test-token'};
  }
  if(command==='push_binding'){Object.assign(current,{enabled:true,userId:data.userId,id:data.id});return {...current};}
  if(command==='push_disable'){Object.assign(current,{enabled:false,id:0,userId:0});delete current.token;return {...current};}
  throw new Error('Beklenmeyen komut');
 };
 globalThis.fetch=async(url,init)=>{
  requests.push({url,init});
  if(url.endsWith('/config'))return Response.json({android:!options.unavailable,ios:!options.unavailable});
  if(options.serverFailure)return Response.json({error:'Bağlantı geçici olarak kullanılamıyor.'},{status:503});
  return Response.json({id:5,sound:options.role!=='client'||options.current?.sound!==false,refreshToken:'test-only'});
 };
 const module=await import('../public/mobile-notifications.js?permission='+sequence);
 const dialog=()=>nodes.find(n=>n.tagName==='dialog'&&n.isConnected);
 return {module,current,calls,requests,storage,nodes,dialog,choose:value=>dialog().querySelector(`[data-permission-${value}]`).onclick(),
  cleanup(){for(const [key,value] of Object.entries(previous)){if(value===undefined)delete globalThis[key];else globalThis[key]=value;}}};
}
for(const [kind,role] of [['android','admin'],['android','client'],['ios','admin'],['ios','client']]){
 test(`${kind} ${role}: ilk girişte izin ve sunucu kaydı otomatik tamamlanır`,async()=>{
  const s=await setup({kind,role});try{
   const pending=s.module.initializeMobile({id:1,role});await tick();
   assert.ok(s.dialog());assert.equal(s.calls.some(c=>c.command==='push_enable'),false);
   s.choose('allow');await pending;
   assert.equal(s.module.mobileActive(),true);assert.equal(s.calls.at(-1).command,'push_binding');
   const payload=JSON.parse(s.requests.find(r=>r.url.endsWith('/register')).init.body);
   assert.equal(payload.kind,kind);assert.equal(payload.appId,s.current.appId);
   assert.deepEqual([...s.storage.values()],['allowed']);assert.equal(JSON.stringify([...s.storage]).includes('test-token'),false);
  }finally{s.cleanup();}
 });
}
test('İzin verme seçimi yeniden girişte ve sayfa yenilemesinde tekrar sorulmaz',async()=>{
 const s=await setup();try{
  let pending=s.module.initializeMobile({id:1,role:'admin'});await tick();s.choose('decline');await pending;
  await s.module.initializeMobile({id:1,role:'admin'});
  const reloaded=await import('../public/mobile-notifications.js?permission=reload-'+sequence);await reloaded.initializeMobile({id:1,role:'admin'});
  assert.equal(s.dialog(),undefined);assert.equal(s.calls.some(c=>c.command==='push_enable'),false);assert.equal(s.requests.some(r=>r.url.endsWith('/register')),false);
 }finally{s.cleanup();}
});
test('Telefon izin vermediğinde giriş kilitlenmez ve sonraki açılışta tekrar istenmez',async()=>{
 const s=await setup({deny:true});try{
  const pending=s.module.initializeMobile({id:1,role:'admin'});await tick();s.choose('allow');await pending;
  assert.equal(s.module.mobileActive(),false);assert.deepEqual([...s.storage.values()],['declined']);
  assert.ok(document.getElementById('mobilePermissionError'));
  await s.module.initializeMobile({id:1,role:'admin'});assert.equal(s.dialog(),undefined);
  assert.equal(s.calls.filter(c=>c.command==='push_enable').length,1);
 }finally{s.cleanup();}
});
test('İzin verildikten sonra sunucu hatası eski sonucu başarılı göstermez; yeniden açılışta izinsiz soru tekrarlanmaz',async()=>{
 const options={serverFailure:true};const s=await setup(options);try{
  const pending=s.module.initializeMobile({id:1,role:'admin'});await tick();s.choose('allow');await pending;
  assert.equal(s.module.mobileActive(),false);assert.deepEqual([...s.storage.values()],['allowed']);
  assert.equal(s.calls.some(c=>c.command==='push_binding'),false);
  options.serverFailure=false;await s.module.initializeMobile({id:1,role:'admin'});
  assert.equal(s.dialog(),undefined);assert.equal(s.module.mobileActive(),true);
 }finally{s.cleanup();}
});
test('Bildirim belirteci alınamazsa kabul korunur ve sonraki açılışta tekrar denenir',async()=>{
 const options={tokenFailure:true};const s=await setup(options);try{
  const pending=s.module.initializeMobile({id:1,role:'admin'});await tick();s.choose('allow');await pending;
  assert.equal(s.module.mobileActive(),false);assert.deepEqual([...s.storage.values()],['allowed']);
  options.tokenFailure=false;await s.module.initializeMobile({id:1,role:'admin'});assert.equal(s.module.mobileActive(),true);
 }finally{s.cleanup();}
});
test('Aynı anda gelen mobil köprü ve oturum olayı tek izin ekranı ve kayıt oluşturur',async()=>{
 const s=await setup();try{
  const a=s.module.initializeMobile({id:1,role:'admin'}),b=s.module.initializeMobile({id:1,role:'admin'});
  assert.equal(a,b);await tick();assert.equal(s.nodes.filter(n=>n.tagName==='dialog').length,1);
  s.choose('allow');await Promise.all([a,b]);assert.equal(s.requests.filter(r=>r.url.endsWith('/register')).length,1);
 }finally{s.cleanup();}
});
test('İzin ekranı açıkken çıkış yapılırsa ekran kapanır ve eski hesaba kayıt olmaz',async()=>{
 const s=await setup();try{
  const pending=s.module.initializeMobile({id:1,role:'admin'});await tick();assert.ok(s.dialog());
  await s.module.stopMobile();await pending;assert.equal(s.dialog(),undefined);assert.equal(s.module.mobileActive(),false);
  assert.equal(s.requests.some(r=>r.url.endsWith('/register')),false);assert.equal(s.storage.size,0);
 }finally{s.cleanup();}
});
test('Mevcut izin korunur; çıkış ve yeniden girişte ayarlara gerek kalmadan cihaz bağlanır',async()=>{
 const s=await setup({current:{enabled:true,permission:true,token:'existing-token',id:5,userId:1}});try{
  await s.module.initializeMobile({id:1,role:'admin'});assert.equal(s.dialog(),undefined);
  await s.module.stopMobile();await s.module.initializeMobile({id:1,role:'admin'});
  assert.equal(s.dialog(),undefined);assert.equal(s.module.mobileActive(),true);
 }finally{s.cleanup();}
});
test('Telefon ayarlarında sonradan kapatılan izin zorlanmaz',async()=>{
 const s=await setup({current:{enabled:false,permission:false,id:5,userId:1}});try{
  await s.module.initializeMobile({id:1,role:'admin'});
  assert.equal(s.dialog(),undefined);assert.equal(s.module.mobileActive(),false);assert.equal(s.calls.some(c=>c.command==='push_enable'),false);
 }finally{s.cleanup();}
});
test('Başka hesaba ait cihaz önce ayrılır, yalnız yeni hesabın kaydı bağlanır',async()=>{
 const s=await setup({current:{enabled:true,permission:true,token:'old-token',id:5,userId:1}});try{
  await s.module.initializeMobile({id:2,role:'client'});
  assert.equal(s.dialog(),undefined);assert.equal(s.calls.find(c=>c.command==='push_binding').data.userId,2);
  assert.ok(s.calls.findIndex(c=>c.command==='push_disable')<s.calls.findIndex(c=>c.command==='push_binding'));
 }finally{s.cleanup();}
});
test('Hizmet hazır değilse izin tüketilmez ve masaüstünde mobil pencere çıkmaz',async()=>{
 const s=await setup({unavailable:true});try{
  await s.module.initializeMobile({id:1,role:'client'});assert.equal(s.dialog(),undefined);assert.equal(s.storage.size,0);
  delete window.__OMNIGO_MOBILE_PUSH__;const count=s.calls.length;
  await s.module.initializeMobile({id:1,role:'admin'});assert.equal(s.calls.length,count);
 }finally{s.cleanup();}
});
test('İzin beklenirken portalın açılması engellenmez',async()=>{
 const s=await setup();try{
  const {initializeBackground,stopBackground}=await import('../public/background-notifications.js?permission=nonblocking');
  await initializeBackground({id:1,role:'admin'});await tick();assert.ok(s.dialog());
  await stopBackground();assert.equal(s.dialog(),undefined);
 }finally{s.cleanup();}
});
test('Sesli bildirim politikası: açık cihazda kapatma ve sessize alma düğmeleri görünmez',async()=>{
 const s=await setup({current:{enabled:true,permission:true,token:'existing-token',id:5,userId:1}});try{
  const user={id:1,role:'admin'};await s.module.initializeMobile(user);
  const root=new Element('section',s.nodes);root.isConnected=true;root.controls={};
  for(const name of ['enable-order-alerts','order-sound','test-push','order-alert-status'])root.controls['[data-'+name+']']=new Element('button',s.nodes);
  s.module.bindMobileControls(root,user);
  assert.equal(root.querySelector('[data-enable-order-alerts]').hidden,true);assert.equal(root.querySelector('[data-order-sound]').hidden,true);
  await root.querySelector('[data-enable-order-alerts]').onclick();assert.equal(s.module.mobileActive(),true);
  assert.equal(root.querySelector('[data-order-sound]').onclick,null);
 }finally{s.cleanup();}
});
test('Giriş yapılmadan izin ya da cihaz kaydı istenmez',async()=>{
 const s=await setup();try{await s.module.initializeMobile(null);assert.equal(s.calls.length,0);assert.equal(s.dialog(),undefined);}finally{s.cleanup();}
});
test('PC: ilk açılışta izin sorulur, kabul edilmeden cihaz kaydı yapılmaz ve tekrar sorulmaz',async()=>{
 const s=await setup();try{
  delete window.__OMNIGO_MOBILE_PUSH__;const nativeCalls=[];let current={deviceId:'d'.repeat(64),id:0,userId:0,sound:true,optedOut:false};
  window.__OMNIGO_BACKGROUND__=async(command,data)=>{nativeCalls.push(command);if(command==='status')return current;if(command==='configure')current={...current,...data};};
  globalThis.fetch=async()=>Response.json({id:5,userId:1,enabled:true,sound:true,token:'test-only',cursor:0});
  const mod=await import('../public/background-notifications.js?desktop=first');
  const pending=mod.initializeBackground({id:1,role:'admin'});await tick();assert.ok(s.dialog());assert.equal(nativeCalls.includes('configure'),false);
  s.choose('allow');await pending;assert.equal(mod.backgroundActive(),true);assert.equal(nativeCalls.includes('configure'),true);
  await mod.initializeBackground({id:1,role:'admin'});assert.equal(s.dialog(),undefined);await mod.stopBackground();
 }finally{s.cleanup();}
});
test('Tarayıcı: ilk izin seçimi sistem iznini açar, izin verilince abonelik kaydolur',async()=>{
 const s=await setup(),previous={navigator:Object.getOwnPropertyDescriptor(globalThis,'navigator'),Notification:globalThis.Notification,location:globalThis.location};try{
  delete window.__OMNIGO_MOBILE_PUSH__;let prompted=0,registered=0;
  globalThis.Notification={permission:'default',requestPermission:async()=>{prompted++;Notification.permission='granted';return 'granted';}};window.Notification=Notification;window.PushManager=function(){};
  const subscription={toJSON:()=>({endpoint:'https://example.invalid/mock'})};
  Object.defineProperty(globalThis,'navigator',{configurable:true,value:{userAgent:'Test Browser',platform:'Win32',maxTouchPoints:0,serviceWorker:{ready:Promise.resolve({pushManager:{getSubscription:async()=>null,subscribe:async()=>subscription}})}}});
  globalThis.location={host:'fixture.invalid',origin:'https://fixture.invalid'};
  globalThis.fetch=async(url)=>{if(url.endsWith('/config'))return Response.json({enabled:true,publicKey:'AA'});registered++;return Response.json({id:7,userId:1,enabled:true,sound:true});};
  const mod=await import('../public/background-notifications.js?web=first');const pending=mod.initializeBackground({id:1,role:'admin'});await tick();assert.ok(s.dialog());assert.equal(prompted,0);
  s.choose('allow');await pending;assert.equal(prompted,1);assert.equal(registered,1);assert.equal(mod.backgroundActive(),true);
 }finally{s.cleanup();if(previous.navigator)Object.defineProperty(globalThis,'navigator',previous.navigator);for(const name of ['Notification','location'])if(previous[name]===undefined)delete globalThis[name];else globalThis[name]=previous[name];}
});

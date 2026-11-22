import test from 'node:test';
import assert from 'node:assert/strict';
import {createLoginUpdateChecker,browserLoginUpdateChecker,newerVersion,attachLoginUpdates,attachClientSession} from '../public/login-updates.js';

for(const channel of ['admin','client'])test(`${channel}: Store/yerel Windows güncellemesi NSIS sürümü yüzünden girişi kilitlemez`,async()=>{
 const paths=[];let nativeChecks=0,version='0.9.160';
 const checker=createLoginUpdateChecker({channel,desktopVersion:'0.2.31',nativeDesktopUpdates:true,
  read:async path=>{paths.push(path);assert.equal(path,'/version.json');return {version};},install:async()=>nativeChecks++});
 assert.deepEqual(await checker.check(),{kind:'ready',version,nativeDesktopUpdates:true});
 assert.equal(nativeChecks,0,'Giriş sırasında kurulum veya mağaza penceresi açılmamalı');
 await checker.checkNativeUpdates();assert.equal(nativeChecks,1);
 assert.equal((await checker.check()).kind,'ready','Store bilgilendirmesinden sonra giriş açık kalmalı');
 version='0.9.161';assert.equal((await checker.check()).kind,'web','Web sürümü değişince yenileme korunmalı');
 assert(paths.every(path=>path==='/version.json'));
});

test('Mevcut Windows köprüsü iki kurulum türünde de yerel güncelleme kanalına yönlenir',async()=>{
 const previousWindow=globalThis.window,previousFetch=globalThis.fetch,paths=[],calls=[];
 globalThis.window={__OMNIGO_DESKTOP__:true,__OMNIGO_DESKTOP_VERSION__:'0.2.31',__TAURI_INTERNALS__:{invoke:async(command,args)=>calls.push({command,args})}};
 globalThis.fetch=async path=>{paths.push(path);return {ok:true,json:async()=>({version:'0.9.160'})};};
 try{
  const checker=browserLoginUpdateChecker('admin');
  assert.equal((await checker.check()).kind,'ready');assert.deepEqual(calls,[]);
  await checker.checkNativeUpdates();assert.deepEqual(calls,[{command:'desktop_action',args:{action:'check_updates'}}]);
  assert(paths.every(path=>path==='/version.json'));
 }finally{if(previousWindow===undefined)delete globalThis.window;else globalThis.window=previousWindow;globalThis.fetch=previousFetch;}
});
test('Android APK sürümü web sürümünden ayrı kontrol edilir; doğru imzalı pakete yönlenir',async()=>{
 const paths=[],opened=[],url='https://portal.omnigoglobal.com/downloads/android/Omnigo-Global-Musteri-Portali-1.0.11.apk';
 const checker=createLoginUpdateChecker({channel:'client',androidVersion:'1.0.9',read:async path=>{paths.push(path);return path.includes('android')?{version:'1.0.11',url}:{version:'0.9.123'};},downloadAndroid:value=>opened.push(value)});
 const update=await checker.check();assert.equal(update.kind,'android');assert.equal(update.version,'1.0.11');
 await assert.rejects(checker.apply(update),/uygulamayı silmeyin/);assert.deepEqual(opened,[url]);assert(paths.includes('/updates/android/client/latest.json'));
 const unsafe=createLoginUpdateChecker({channel:'admin',androidVersion:'1.0.9',read:async path=>path.includes('android')?{version:'1.0.11',url}:{version:'0.9.123'}});
 await assert.rejects(unsafe.check(),/adresi doğrulanamadı/);
});
test('Yönetici girişi: yeni Windows sürümü, iptal edilen kurulum ve ağ hatası geçişi açmaz',async()=>{
 let installed=0,fail=false;
 const checker=createLoginUpdateChecker({desktopVersion:'0.2.15',read:async path=>{if(fail)throw new Error('Ağ hatası');return {version:path.includes('updates')?'0.2.16':'0.9.73'};},install:async()=>{installed++;},reload(){throw new Error('Yanlış yenileme');}});
 const update=await checker.check();assert.equal(update.kind,'desktop');await assert.rejects(checker.apply(update),/tamamlayıp/);assert.equal(installed,1);assert.equal((await checker.check()).kind,'desktop');fail=true;await assert.rejects(checker.check(),/Ağ hatası/);
});
test('Güncel Windows girişe hazır; sayfa açıkken web sürümü değişirse yenileme gerekir',async()=>{
 let version='0.9.73',reloads=0;
 const checker=createLoginUpdateChecker({desktopVersion:'0.2.16',read:async path=>({version:path.includes('updates')?'0.2.16':version}),reload:()=>reloads++});
 assert.equal((await checker.check()).kind,'ready');version='0.9.74';const pending=await checker.check();assert.equal(pending.kind,'web');await checker.apply(pending);assert.equal(reloads,1);
});
test('Sürüm numaraları metinsel değil sayısal karşılaştırılır; hatalı sürüm açılmaz',()=>{assert.equal(newerVersion('0.2.10','0.2.9'),true);assert.equal(newerVersion('0.2.9','0.2.10'),false);assert.throws(()=>newerVersion('hatalı','0.2.10'));});

test('Müşteri sürümü yalnız müşteri paketini okur; yönetici paketine yönlenmez',async()=>{
 const paths=[];
 const checker=createLoginUpdateChecker({channel:'client',desktopVersion:'0.2.15',read:async path=>{paths.push(path);return {version:path.includes('updates')?'0.2.16':'0.9.84'};}});
 assert.deepEqual(await checker.check(),{kind:'desktop',version:'0.2.16'});
 assert(paths.includes('/updates/client/latest.json'));assert(!paths.some(path=>path.includes('/admin/')));
 assert.throws(()=>createLoginUpdateChecker({channel:'../admin'}),/türü/);
});

test('Giriş ekranı güncelleme ve ağ hatasında kapalı kalır; güncel sürümde geçiş verir',async()=>{
 const previous=globalThis.document,button={},status={},box={setAttribute(){},classList:{toggle(){}},querySelector:q=>q==='button'?button:status};
 globalThis.document={createElement:()=>box};
 try{
  let mode='web',applied=0;
  const gate=attachLoginUpdates({before(value){assert.equal(value,box);}},{check:async()=>{if(mode==='error')throw new Error('Bağlantı hatası');return {kind:mode,version:'0.9.84'};},apply:async()=>applied++});
  assert.equal(box.hidden,true,'Giriş ekranında rutin güncelleme kutusu görünmez');
  await assert.rejects(gate.beforeLogin(),/güncellemeyi tamamlayın/);assert.match(button.textContent,/yenile/);
  assert.equal(box.hidden,false,'Gerekli web yenilemesi erişilebilir kalır');
  await button.onclick();assert.equal(applied,1);
  mode='error';await assert.rejects(gate.beforeLogin(),/Bağlantı hatası/);assert.equal(button.disabled,false);
  assert.equal(box.hidden,false,'Kontrol hatasında tekrar deneme gösterilir');
  mode='ready';await gate.beforeLogin();assert.equal(button.textContent,'Güncellemeler');assert.equal(box.hidden,true);
 }finally{globalThis.document=previous;}
});

test('Hatırlanan müşteri oturumu sunucuda yeniden doğrulanabilir; hesap değiştirince şifre gerekir',()=>{
 const previous=globalThis.document;
 const nodes=[];globalThis.document={createElement:()=>{const node={remove(){this.removed=true;}};nodes.push(node);return node;}};
 try{
  const input=()=>({required:true,value:'',label:{hidden:false},closest(){return this.label;},focus(){this.focused=true;}});
  const form={elements:{email:input(),password:input(),keepSession:input()},prepend(){}};
  form.loginIdentity={choice:{hidden:false},set(mode,value){form.elements.email.value=value;}};
  const user={id:2,role:'client',name:'Örnek müşteri',email:'musteri@example.test'};
  const session=attachClientSession(form,user);assert.equal(session.resumeAccount(),user);assert.equal(form.elements.password.required,false);assert.equal(form.elements.email.value,user.email);assert.equal(form.elements.keepSession.label.hidden,true);
  nodes[1].onclick();assert.equal(session.resumeAccount(),null);assert.equal(form.elements.password.required,true);assert.equal(form.elements.password.label.hidden,false);assert.equal(form.elements.keepSession.label.hidden,false);
  assert.equal(attachClientSession(form,{...user,role:'admin'}),null);
 }finally{globalThis.document=previous;}
});

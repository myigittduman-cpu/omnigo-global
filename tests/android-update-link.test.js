import test from 'node:test';
import assert from 'node:assert/strict';
import {androidRelease,androidBrowserHandoff} from '../public/android-update-link.js';
import {browserLoginUpdateChecker} from '../public/login-updates.js';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import {t as i18nT,text as i18nText,html as i18nHtml} from '../public/i18n.js';
test('Yeni marka adı eski APK indirme adreslerini bozmaz; 1.0.27 yeni dosya adını kullanır',()=>{
 assert.match(androidRelease('admin','1.0.18').url,/Yonetici-Paneli-1\.0\.18\.apk$/);
 assert.match(androidRelease('admin','1.0.27').url,/Yonetici-Portali-1\.0\.27\.apk$/);
 assert.equal(androidRelease('admin','1.0.18').title,'OG Yönetici Portalı');
});
for(const role of ['admin','client']){
 test(role+': eski APK güncellemesi ana pencereden dış tarayıcıya geçer ve doğru uygulamayı korur',async()=>{
  const release=androidRelease(role,'1.0.12'),opened=[];
  const previous={};for(const key of ['window','navigator','location','fetch'])previous[key]=Object.getOwnPropertyDescriptor(globalThis,key);
  Object.defineProperties(globalThis,{
   window:{value:{},configurable:true},navigator:{value:{userAgent:'Android OmnigoAndroid/1.0.9'},configurable:true},
   location:{value:{assign:url=>opened.push(url)},configurable:true},
   fetch:{value:async path=>({ok:true,json:async()=>path.includes('/android/')?{version:'1.0.12',url:release.url}:{version:'0.9.125'}}),configurable:true},
  });
  try{
   const checker=browserLoginUpdateChecker(role),update=await checker.check();assert.equal(update.kind,'android');
   await assert.rejects(checker.apply(update),/uygulamayı silmeyin/);
   assert.equal(opened.length,1);const target=new URL(opened[0]);
   assert.notEqual(target.host,new URL(release.url).host);assert.equal(target.protocol,'https:');
   assert.equal(target.pathname,'/android-install.html');assert.equal(target.searchParams.get('app'),role);
   assert.equal(androidRelease(target.searchParams.get('app'),target.searchParams.get('version')).url,release.url);
  }finally{for(const [key,descriptor] of Object.entries(previous)){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else delete globalThis[key];}}
 });
 test(role+': güncelleme yönlendirmesi yabancı URL ve farklı uygulama paketini reddeder',()=>{
  const valid=androidRelease(role,'1.0.12').url;
  for(const bad of [valid+'?url=https://evil.test',valid+'#hash',valid.replace('https:','http:'),valid.replace('omnigoglobal.com','evil.test'),androidRelease(role==='admin'?'client':'admin','1.0.12').url,null])assert.throws(()=>androidBrowserHandoff(role,bad));
 });
}
test('kurulum sayfası serbest URL, rol veya sürüm kabul etmez',()=>{
 for(const role of ['constructor','__proto__','bad','../admin'])assert.throws(()=>androidRelease(role,'1.0.12'));
 for(const version of [null,'1..12','1.0.12.apk','https://evil.test','1.0.12/../../file'])assert.throws(()=>androidRelease('admin',version));
});
test('tarayıcı kurulum sayfası doğru APK dosyasını açar; hatalı parametrelerde indirme başlatmaz',async()=>{
 const script=(await fs.readFile(new URL('../public/android-install.js',import.meta.url),'utf8')).replace(/^import[^\n]+\n/gm,'');
 for(const role of ['admin','client','invalid']){
  const nodes={'#installStatus':{},'#downloadApk':{hidden:true},'#appTitle':{},'#apkFilename':{}},downloads=[];
  const context={i18nT,i18nText,i18nHtml,androidRelease,URLSearchParams,navigator:{userAgent:'Chrome Android'},document:{querySelector:q=>nodes[q]},location:{search:'?app='+role+'&version=1.0.12',replace:url=>downloads.push(url)}};
  vm.runInNewContext(script,context);
  if(role==='invalid'){assert.deepEqual(downloads,[]);assert.equal(nodes['#downloadApk'].hidden,true);assert.match(nodes['#installStatus'].textContent,/doğrulanamadı/);}
  else{assert.deepEqual(downloads,[androidRelease(role,'1.0.12').url]);assert.equal(nodes['#downloadApk'].href,downloads[0]);assert.equal(nodes['#downloadApk'].hidden,false);assert.equal(nodes['#appTitle'].textContent,i18nT(androidRelease(role,'1.0.12').title));assert.equal(nodes['#apkFilename'].textContent,androidRelease(role,'1.0.12').title+'.apk');}
 }
});

import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createRequire} from 'node:module';
import {mobileChannel} from '../android/mobile/configuration.mjs';
import vm from 'node:vm';
import {installationManifest,usesMobileIcons} from '../public/installation-icons.js';
const require=createRequire(import.meta.url),{createManifest}=require('../desktop/windows/store/manifest.cjs');
const identities=require('../desktop/windows/store/identities.json'),privateAdmin=require('../desktop/windows/store/private-admin.json');

test('Kurulu uygulama adları kısadır; mevcut mağaza ve özel Android kimlikleri korunur',()=>{
 for(const [role,label] of [['admin','OG Yönetici Portalı'],['client','OG İş Ortağım']]){
  const channel=mobileChannel(role),identity=role==='admin'?privateAdmin:identities.client;
  assert.equal(channel.launcherName.replaceAll('\u00a0',' '),label);
  const xml=createManifest(identity,identities.publisher,identities.publisherDisplayName,'0.2.41.0');
  assert.ok(xml.includes(`<DisplayName>${identity.displayName}</DisplayName>`));
  assert.ok(xml.includes(`<uap:VisualElements DisplayName="${label}"`));
  assert.ok(xml.includes(`$(Desktop)\\${label}.lnk`));
  assert.ok(xml.includes('Icon="$(Package)\\Simgeler\\Omnigo-Klasik.ico"'));
  assert.ok(xml.includes(`Name="${identity.name}"`));
 }
 assert.equal(mobileChannel('admin',{privateDistribution:true}).applicationId,'com.omnigoglobal.admin.privateapp');
 assert.equal(mobileChannel('client').applicationId,'com.omnigoglobal.client');
});

test('Ana ekrana ekleme iki rolün ayrı simgesini kullanırken yön değiştirme açık kalır',async()=>{
 for(const [role,label] of [['admin','OG Yönetici Portalı'],['client','OG İş Ortağım']]){
  const manifest=JSON.parse(await fs.readFile(new URL(`../public/${role}.webmanifest`,import.meta.url),'utf8'));
  assert.equal(manifest.name,label);assert.equal(manifest.short_name.replaceAll('\u00a0',' '),label);assert.ok(manifest.short_name.includes('\u00a0'));assert.equal(manifest.orientation,'any');
  for(const icon of manifest.icons){assert.ok(icon.src.includes(`app-${role}-`));await fs.access(new URL('../public'+icon.src,import.meta.url));}
 }
});

test('İlk yüklemede kurulum betiği doğru rolün adını ve simgesini seçer',async()=>{
 const source=(await fs.readFile(new URL('../public/install.js',import.meta.url),'utf8')).replace(/^import[^\n]+\n/gm,'');
 for(const [role,host,label] of [['admin','yonetim.omnigoglobal.com','OG Yönetici Portalı'],['client','portal.omnigoglobal.com','OG İş Ortağım']]){
  const touch={sizes:{value:'180x180'}},large={sizes:{value:'1024x1024'}},title={},manifest={},app={},container={append:()=>{}};
  const document={title:'',head:{appendChild:value=>value},createElement:()=>({}),getElementById:()=>app,querySelectorAll:()=>[touch,large],querySelector:selector=>selector.includes('apple-touch-icon')?touch:selector.includes('apple-mobile-web-app-title')?title:selector.includes('manifest')?manifest:container};
  const context={document,location:{hostname:host},navigator:{},installationManifest,i18nT:value=>value,matchMedia:()=>({matches:true}),MutationObserver:class{observe(){}},window:{addEventListener(){}}};
  vm.runInNewContext(source,context);
  assert.equal(title.content.replaceAll('\u00a0',' '),label);assert.ok(title.content.includes('\u00a0'));assert.equal(touch.href,`/assets/app-${role}-mobile-180-v3.png`);assert.equal(large.href,`/assets/app-${role}-mobile-1024-v3.png`);assert.equal(manifest.href,`/${role}.webmanifest`);
 }
});
test('Mobil ana ekran simgesi iki rolde yazısızdır; Windows dokunmatik dizüstü masaüstü simgesini korur',async()=>{
 for(const navigator of [{userAgent:'Android 15'},{userAgent:'iPhone'},{userAgent:'Macintosh',platform:'MacIntel',maxTouchPoints:5}]){
  assert.equal(usesMobileIcons(navigator),true);
  for(const role of ['admin','client']){assert.equal(installationManifest(role,navigator),`/${role}-mobile-v3.webmanifest`);const mobile=JSON.parse(await fs.readFile(new URL(`../public/${role}-mobile-v3.webmanifest`,import.meta.url)));const desktop=JSON.parse(await fs.readFile(new URL(`../public/${role}.webmanifest`,import.meta.url)));for(const key of ['id','name','short_name','start_url','scope','display','orientation'])assert.equal(mobile[key],desktop[key]);assert(mobile.icons.every(icon=>icon.src.startsWith(`/assets/app-${role}-mobile-`)));}
 }
 assert.equal(installationManifest('admin',{userAgent:'Windows NT 10.0',maxTouchPoints:10}),'/admin.webmanifest');
});

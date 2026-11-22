import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import {welcomeScreenView} from '../public/welcome-screen-view.js';

const source=await fs.readFile(new URL('../public/welcome-screen.js',import.meta.url),'utf8');
function launch({userAgent='',native=false,gate=false}={}){
 let removed=0,nextFrame;const timers=[],app={inert:false},classes=new Set();
 const status={textContent:'Çalışma alanınız hazırlanıyor…'};
 const welcome={remove:()=>removed++,classList:classes,querySelector:selector=>selector==='[data-welcome-status]'?status:null};
 const context={navigator:{userAgent},performance:{now:()=>0},Event,requestAnimationFrame:fn=>(nextFrame=fn,1),cancelAnimationFrame:()=>{},clearTimeout:()=>{},setTimeout:fn=>(timers.push(fn),timers.length),addEventListener:()=>{},removeEventListener:()=>{},dispatchEvent:()=>{},__OMNIGO_NATIVE_WELCOME__:native,__OMNIGO_STARTUP_GATE__:gate};
 context.document={getElementById:id=>id==='app'?app:welcome,documentElement:{dataset:{},classList:{add(){},remove(){}}},visibilityState:'visible',addEventListener:()=>{}};
 context.window=context;vm.runInNewContext(source,context);
 return {app,classes,status,removed:()=>removed,tick:time=>nextFrame?.(time),fallback:()=>timers.at(-1)?.()};
}
test('İki uygulamanın ilk HTML yanıtı aynı açılış kartını kendi adıyla gösterir',async()=>{
 const admin=welcomeScreenView('admin'),client=welcomeScreenView('client');
 assert.equal(admin.replaceAll('Yönetici Portalı','İş Ortağım').replace(' omnigo-welcome-admin',''),client);
 for(const [entry,markup] of [['admin-index.html',admin],['index.html',client]]){
  const page=await fs.readFile(new URL('../public/'+entry,import.meta.url),'utf8');
  assert.ok(page.includes(markup+'<div id="app">'));
  assert.equal(page.match(/id="omnigoWelcome"/g)?.length,1);
  assert.match(markup,/class="omnigo-welcome-frame"/);
  assert.match(markup,/class="omnigo-welcome-dots"/);
  assert.match(markup,/class="omnigo-welcome-check"/);
 }
});
test('Android native karşılaması hazır olmadan gelen web belgesi ikinci bir karşılama başlatmaz',()=>{
 for(const userAgent of ['Mozilla/5.0 OmnigoAndroid/1.0.24 Store','Mozilla/5.0 OmnigoAndroid/1.0.25 Store']){
  const view=launch({userAgent});assert.equal(view.removed(),1);assert.equal(view.app.inert,false);
 }
 const windows=launch({native:true});assert.equal(windows.removed(),1);assert.equal(windows.app.inert,false);
});
test('Tarayıcı iki rolde hazır işaretini gösterir ve beş saniyede uygulamaya geçer',()=>{
 const view=launch();view.tick(0);view.tick(4399);assert.equal(view.classes.has('omnigo-welcome-ready'),false);
 view.tick(4400);assert.ok(view.classes.has('omnigo-welcome-ready'));assert.equal(view.status.textContent,'Çalışma alanınız hazır');
 view.tick(4860);assert.ok(view.classes.has('omnigo-welcome-leaving'));assert.equal(view.app.inert,true);
 view.tick(5000);assert.equal(view.removed(),1);assert.equal(view.app.inert,false);
});
test('Başlatma sinyali gelmezse bekleme katmanı uygulamayı sonsuza kadar kilitlemez',()=>{
 const view=launch({gate:true});view.tick(0);assert.equal(view.app.inert,true);view.fallback();assert.equal(view.app.inert,false);assert.equal(view.removed(),1);
});

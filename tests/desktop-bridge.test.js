import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
const source=await fs.readFile(new URL('../desktop/src/desktop.js',import.meta.url),'utf8');
function page(host, origin='https://'+host+'.omnigoglobal.com',platform='test') {
 const window=new EventTarget();window.top=window;
 const env={window,Event,location:{origin},document:{querySelectorAll:()=>[],addEventListener(){}},MutationObserver:class {observe(){}}};
 const script=source.replaceAll('__PORTAL_ORIGIN__','https://'+host+'.omnigoglobal.com').replaceAll('__DESKTOP_VERSION__','0.2.3').replaceAll('__DESKTOP_PLATFORM__',platform);
 vm.runInNewContext(script,env);return {window,env,script};
}
for(const host of ['portal','yonetim'])test(host+': native bridge sends exact report bytes and survives a page reload',async()=>{
 const foreign=page(host,'https://example.test');assert.equal(foreign.window.__OMNIGO_SAVE_REPORT__,undefined);
 const {window,env,script}=page(host);
 await assert.rejects(window.__OMNIGO_SAVE_REPORT__('Omnigo-test.pdf','JVBERi0='),/hazır değil/);
 window.__TAURI_INTERNALS__={invoke:async(command,data)=>{
  assert.equal(command,'save_report');assert.equal(data.filename,'Omnigo-test.pdf');assert.equal(data.contents,'JVBERi0=');return 'saved';
 }};
 vm.runInNewContext(script,env);
 assert.equal(await window.__OMNIGO_SAVE_REPORT__('Omnigo-test.pdf','JVBERi0='),'saved');
 window.__TAURI_INTERNALS__.invoke=async()=> 'cancelled';assert.equal(await window.__OMNIGO_SAVE_REPORT__('Omnigo-test.pdf','JVBERi0='),'cancelled');
 window.__TAURI_INTERNALS__.invoke=async()=>{throw 'Dosya yazılamadı.';};await assert.rejects(window.__OMNIGO_SAVE_REPORT__('Omnigo-test.pdf','JVBERi0='),/Dosya yazılamadı/);
});

test('Masaüstü dil komutu yalnız desteklenen dili ve mevcut IPC kapsamını kullanır',async()=>{
 const {window}=page('portal'),requests=[];
 window.__TAURI_INTERNALS__={invoke:async(command,data)=>requests.push({command,...data})};
 for(const language of ['tr','en','de'])await window.__OMNIGO_SET_LANGUAGE__(language);
 for(const language of ['fr','en;save_report',null,{}])await window.__OMNIGO_SET_LANGUAGE__(language);
 assert.deepEqual(requests,[{command:'desktop_action',action:'language:tr'},{command:'desktop_action',action:'language:en'},{command:'desktop_action',action:'language:de'}]);
 assert.equal(page('portal','https://example.test').window.__OMNIGO_SET_LANGUAGE__,undefined);
});

test('Web sitesi köprüsü yalnız sabit masaüstü eylemini çağırır ve dış çerçevede çalışmaz',async()=>{
 const {window}=page('yonetim'),calls=[];
 window.__TAURI_INTERNALS__={invoke:async(command,data)=>calls.push({command,...data})};
 await window.__OMNIGO_OPEN_WEBSITE__('https://untrusted.invalid/');
 assert.deepEqual(calls,[{command:'desktop_action',action:'open_website'}]);
 window.top={};await assert.rejects(window.__OMNIGO_OPEN_WEBSITE__(),/hazır değil/);
 assert.equal(page('yonetim','https://untrusted.invalid').window.__OMNIGO_OPEN_WEBSITE__,undefined);
});
test('Windows giriş kasası iki uygulamada kendi ana köprüsünde ve kapalı işlem kümesiyle kullanılır',async()=>{
 const {window}=page('yonetim',undefined,'windows');
 const calls=[];window.__TAURI_INTERNALS__={invoke:async(command,data)=>{calls.push([command,data.operation,data.identity]);return true;}};
 await window.__OMNIGO_LOGIN_CREDENTIALS__.get('admin@example.test');await window.__OMNIGO_LOGIN_CREDENTIALS__.store({id:'admin@example.test',password:'Test-Only-Secret!'});await window.__OMNIGO_LOGIN_CREDENTIALS__.clear();
 assert.deepEqual(calls,[['remembered_login','get','admin@example.test'],['remembered_login','store','admin@example.test'],['remembered_login','clear',null]]);
 assert.equal(typeof page('portal',undefined,'windows').window.__OMNIGO_LOGIN_CREDENTIALS__.get,'function');
 assert.equal(page('portal','https://yonetim.omnigoglobal.com','windows').window.__OMNIGO_LOGIN_CREDENTIALS__,undefined);
 assert.equal(page('yonetim','https://untrusted.invalid','windows').window.__OMNIGO_LOGIN_CREDENTIALS__,undefined);
});

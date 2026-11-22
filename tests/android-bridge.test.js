import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import fs from 'node:fs/promises';
import {getChannel} from '../android/channels.mjs';
const script=await fs.readFile(new URL('../android/app/src/main/assets/portal-bridge.js',import.meta.url),'utf8');
function environment(channel,origin=channel.origin,push=false){
 const listeners={},sent=[];
 const window={addEventListener:(name,fn)=>listeners[name]=fn,dispatchEvent:event=>{if(event.type==='omnigo-mobile-ready')sent.push({event:event.type});}};window.top=window;
 class Anchor{click(){}}
 const env={window,Event,location:{origin},document:{querySelectorAll:()=>[]},MutationObserver:class{observe(){}},HTMLAnchorElement:Anchor,setTimeout,clearTimeout};
 const install=token=>vm.runInNewContext(script.replaceAll('__OMNIGO_PORT_TOKEN__',token).replaceAll('__OMNIGO_PORTAL_ORIGIN__',channel.origin).replace('__OMNIGO_PUSH_ENABLED__',String(push)),env);
 const acknowledgement=install('test-nonce');
 const port={start(){},close(){this.closed=true;},postMessage(data){sent.push(JSON.parse(data));}};
 return {window,listeners,sent,port,install,acknowledgement};
}
for (const name of ['client','admin']) {
 const channel=getChannel(name);
test(name+': güvenli giriş kasası kendi kökeninde ve güncel kanal jetonuyla çalışır',async()=>{
 const foreign=environment(channel,'https://example.test');assert.equal(foreign.window.__OMNIGO_LOGIN_CREDENTIALS__,undefined);
 const env=environment(channel);env.listeners.message({data:'omnigo-report-port:wrong',ports:[{start(){assert.fail('Yanlış jeton kabul edildi');}}]});
 env.listeners.message({data:'omnigo-report-port:test-nonce',ports:[env.port]});
 const vault=env.window.__OMNIGO_LOGIN_CREDENTIALS__;assert.equal(Object.isFrozen(vault),true);
 const reading=vault.get('sample@example.test');await new Promise(resolve=>setImmediate(resolve));
 assert.deepEqual(env.sent.at(-1),{id:'1',command:'credentials_get',data:{identity:'sample@example.test',password:null}});
 env.port.onmessage({data:JSON.stringify({id:'1',status:JSON.stringify({type:'password',id:'sample@example.test',password:'Test-Only!12'})})});assert.equal((await reading).password,'Test-Only!12');
 const storing=vault.store({id:'sample@example.test',password:'Test-Only!12'});await new Promise(resolve=>setImmediate(resolve));assert.equal(env.sent.at(-1).command,'credentials_store');env.port.onmessage({data:JSON.stringify({id:'2',status:'true'})});assert.equal(await storing,true);
 const clearing=vault.clear();await new Promise(resolve=>setImmediate(resolve));assert.deepEqual(env.sent.at(-1),{id:'3',command:'credentials_clear',data:{identity:null,password:null}});env.port.onmessage({data:JSON.stringify({id:'3',status:'true'})});assert.equal(await clearing,true);
 const pending=vault.get('sample@example.test'),rejection=assert.rejects(pending,/Sayfa bağlantısı yenilendi/);await new Promise(resolve=>setImmediate(resolve));env.install('new-nonce');await rejection;
});
test(name+': native push commands match the Android handler and decode JSON replies',async()=>{
 const env=environment(channel,channel.origin,true);
 assert.deepEqual(env.sent.shift(),{event:'omnigo-mobile-ready'});
 env.listeners.message({data:'omnigo-report-port:test-nonce',ports:[env.port]});
 const response=env.window.__OMNIGO_MOBILE_PUSH__('push_status');await new Promise(resolve=>setImmediate(resolve));
 assert.deepEqual(env.sent[0],{id:'1',command:'push_status',data:{}});
 env.port.onmessage({data:JSON.stringify({id:'1',status:JSON.stringify({kind:'android',enabled:false})})});
 assert.equal((await response).kind,'android');
 assert.equal(environment(channel,'https://example.test',true).window.__OMNIGO_MOBILE_PUSH__,undefined);
});
test(name+': Android bridge uses only its own origin and requires the native per-page token',async()=>{
 for(const origin of ['https://example.test',getChannel(name==='client'?'admin':'client').origin]) {
  const foreign=environment(channel,origin);assert.equal(foreign.window.__OMNIGO_SAVE_REPORT__,undefined);
 }
 const env=environment(channel);
 const fake={start(){throw new Error('Untrusted port accepted');}};
 env.listeners.message({data:'omnigo-report-port:wrong',ports:[fake]});
 env.listeners.message({data:'omnigo-report-port:test-nonce',ports:[env.port]});
 const result=env.window.__OMNIGO_SAVE_REPORT__('Omnigo-test.pdf','JVBERi0=');
 await new Promise(resolve=>setImmediate(resolve));
 assert.deepEqual(env.sent[0],{id:'1',command:'save_report',filename:'Omnigo-test.pdf',contents:'JVBERi0='});
 env.port.onmessage({data:JSON.stringify({id:'1',status:'saved'})});assert.equal(await result,'saved');
});
test(name+': Android bridge distinguishes cancelled and failed saves',async()=>{
 const env=environment(channel);env.listeners.message({data:'omnigo-report-port:test-nonce',ports:[env.port]});
 const cancelled=env.window.__OMNIGO_SAVE_REPORT__('Omnigo-test.xlsx','UEsDBA==');await new Promise(resolve=>setImmediate(resolve));
 env.port.onmessage({data:JSON.stringify({id:'1',status:'cancelled'})});assert.equal(await cancelled,'cancelled');
 const failed=env.window.__OMNIGO_SAVE_REPORT__('Omnigo-test.pdf','JVBERi0=');await new Promise(resolve=>setImmediate(resolve));
 env.port.onmessage({data:JSON.stringify({id:'2',error:'Dosya kaydedilemedi.'})});await assert.rejects(failed,/Dosya kaydedilemedi/);
});
test(name+': paylaşım ve klasör seçimi farklı yerel komutlardır; bağlantı yenilenince çalışır',async()=>{
 const env=environment(channel);assert.equal(env.acknowledgement,'omnigo-report-ready:test-nonce');
 env.listeners.message({data:'omnigo-report-port:test-nonce',ports:[env.port]});
 const sharing=env.window.__OMNIGO_SHARE_REPORT__('Ankara 21.10.2026 Teslimat Evrakları.pdf','JVBERi0=');await new Promise(resolve=>setImmediate(resolve));
 assert.equal(env.sent[0].command,'share_report');assert.equal(env.sent[0].filename,'Ankara 21.10.2026 Teslimat Evrakları.pdf');
 env.port.onmessage({data:JSON.stringify({id:'1',status:'opened'})});assert.equal(await sharing,'opened');
 assert.equal(env.install('second-nonce'),'omnigo-report-ready:second-nonce');assert.equal(env.port.closed,true);
 const next={start(){},postMessage(data){env.sent.push(JSON.parse(data));}};
 env.listeners.message({data:'omnigo-report-port:test-nonce',ports:[{start(){assert.fail('Eski kanal kabul edildi');}}]});
 env.listeners.message({data:'omnigo-report-port:second-nonce',ports:[next]});
 const saving=env.window.__OMNIGO_SAVE_REPORT__('Kırşehir 21.10.2026 Teslimat Evrakları.xlsx','UEsDBA==');await new Promise(resolve=>setImmediate(resolve));
 assert.equal(env.sent.at(-1).command,'save_report');next.onmessage({data:JSON.stringify({id:env.sent.at(-1).id,status:'saved'})});assert.equal(await saving,'saved');
});
}

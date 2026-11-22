import test from 'node:test';
import assert from 'node:assert/strict';
import {registerWebPush} from '../public/web-push-binding.js';
function fixture(permission='granted'){
 const calls=[];let prompts=0,subscribes=0;
 Object.defineProperty(globalThis,'navigator',{configurable:true,value:{userAgent:'iPhone',platform:'iPhone',standalone:true}});
 globalThis.location={origin:'https://portal.example.test'};globalThis.matchMedia=()=>({matches:true});
 globalThis.Notification={permission,requestPermission:async()=>{prompts++;return 'granted';}};
 const subscription={toJSON:()=>({endpoint:'https://web.push.apple.com/example',keys:{auth:'test',p256dh:'test'}})};
 const manager={getSubscription:async()=>subscription,subscribe:async()=>{subscribes++;return subscription;}};
 const request=async(path,method,data)=>{calls.push({path,method,data});return path==='/push/config'?{enabled:true,publicKey:'AQID'}:{id:7,userId:2,enabled:true,sound:true};};
 return {calls,manager,request,workerReady:async()=>({pushManager:manager}),current:()=>true,prompts:()=>prompts,subscribes:()=>subscribes};
}
test('iPhone mevcut izni ve aboneliğiyle yeni oturuma kaydolur; tekrar izin istemez',async()=>{
 const f=fixture();const result=await registerWebPush(f);
 assert.equal(result.id,7);assert.equal(f.prompts(),0);assert.equal(f.subscribes(),0);
 assert.deepEqual(f.calls[1].data.clientContext,{origin:location.origin,platform:'ios',standalone:true});
});
test('iPhone aboneliği kaybolduğunda verilmiş izinle yeniden oluşturulur',async()=>{
 const f=fixture();f.manager.getSubscription=async()=>null;
 assert.equal((await registerWebPush(f)).enabled,true);assert.equal(f.subscribes(),1);assert.equal(f.prompts(),0);
});
test('kendiliğinden izin penceresi açılmaz; izin yalnız tıklamayla istenir',async()=>{
 const f=fixture('default');assert.equal(await registerWebPush(f),null);assert.equal(f.prompts(),0);assert.equal(f.calls.length,0);
 assert.equal((await registerWebPush({...f,explicit:true})).enabled,true);assert.equal(f.prompts(),1);
});
test('reddedilmiş izinle abonelik gönderilmez',async()=>{
 const f=fixture('denied');assert.equal(await registerWebPush({...f,explicit:true}),null);assert.equal(f.prompts(),0);assert.equal(f.calls.length,0);
});
test('hesap değişimi eski isteğin cihaz kaydı yazmasını engeller',async()=>{
 const f=fixture();let current=true;
 await assert.rejects(registerWebPush({...f,current:()=>current,workerReady:async()=>{current=false;return {pushManager:f.manager};}}),{name:'AbortError'});
 assert.equal(f.calls.some(c=>c.path==='/push/register'),false);
});
test('çıkışta iptal edilen cevap yeni hesabın durumunu ezmez',async()=>{
 const f=fixture(),controller=new AbortController();
 await assert.rejects(registerWebPush({...f,signal:controller.signal,request:async(...args)=>{const result=await f.request(...args);if(args[0]==='/push/register')controller.abort();return result;}}),{name:'AbortError'});
});

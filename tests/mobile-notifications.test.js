import test from 'node:test';
import assert from 'node:assert/strict';
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function setup(){
 const calls=[];globalThis.window={__OMNIGO_MOBILE_PUSH__:async(command,data)=>{
  calls.push({command,data});return command==='push_status'?{kind:'android',appId:'com.omnigoglobal.admin',installationId:'a'.repeat(64),token:'mock-token',enabled:true,permission:true,userId:1,sound:true,displayVersion:2}:{};
 }};return calls;
}
test('Mobil kayıt sunucu yanıtından sonra bağlanır; belirteç web depolamasına yazılmaz',async()=>{
 const calls=setup();const previous=globalThis.fetch;
 globalThis.fetch=async(url,options)=>{if(url.endsWith('/register'))assert.equal(JSON.parse(options.body).displayVersion,2);return Response.json(url.endsWith('/config')?{android:true}:{id:5,refreshToken:'test-only',sound:true});};
 try{
  const module=await import('../public/mobile-notifications.js?test=register');await module.initializeMobile({id:1,role:'admin'});
  assert.equal(module.mobileActive(),true);assert.equal(calls.at(-1).command,'push_binding');assert.equal(calls.at(-1).data.userId,1);
  await module.stopMobile();assert.equal(module.mobileActive(),false);assert.equal(calls.at(-1).command,'push_disable');
 }finally{globalThis.fetch=previous;delete globalThis.window;}
});
test('Çıkıştan sonra tamamlanan eski mobil kayıt hesabı yeniden etkinleştirmez',async()=>{
 const calls=setup();const previous=globalThis.fetch;let complete;
 globalThis.fetch=async url=>url.endsWith('/config')?Response.json({android:true}):new Promise(resolve=>complete=()=>resolve(Response.json({id:5,refreshToken:'test-only',sound:true})));
 try{
  const module=await import('../public/mobile-notifications.js?test=logout');const initializing=module.initializeMobile({id:1,role:'admin'});await tick();assert.ok(complete);
  await module.stopMobile();complete();await initializing;
  assert.equal(module.mobileActive(),false);assert.equal(calls.some(c=>c.command==='push_binding'),false);
 }finally{globalThis.fetch=previous;delete globalThis.window;}
});

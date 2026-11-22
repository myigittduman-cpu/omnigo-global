import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import {storeChannel,javaConfiguration} from '../android/store/configuration.mjs';
import {inspectDex} from '../android/mobile/artifact-validation.mjs';
test('Doğrudan APK güncellemesi mağaza paketine sızmaz',()=>{
 const code=Buffer.from('/downloads/android/ FirebasePushController OmnigoMessagingService PushTokenWorker');
 assert.throws(()=>inspectDex([code]),/Mağaza paketinde/);assert.doesNotThrow(()=>inspectDex([code],{directUpdates:true}));
 assert.match(javaConfiguration(storeChannel('client'),{directUpdates:true}),/ALLOW_DIRECT_UPDATES = true/);
 assert.match(javaConfiguration(storeChannel('client')),/ALLOW_DIRECT_UPDATES = false/);
});
const source=await fs.readFile(new URL('../ios/Resources/portal-bridge.js',import.meta.url),'utf8');

function createIOS({origin='https://portal.omnigoglobal.com',frame=false,response='saved'}={}){
 const requests=[];
 const events=new EventTarget();
 const context={Event,dispatchEvent:events.dispatchEvent.bind(events),location:{origin},navigator:{},document:{querySelectorAll:()=>[]},MutationObserver:class{observe(){}},
  HTMLAnchorElement:class{},DOMException,FileReader:class{readAsDataURL(){this.result='data:application/pdf;base64,JVBERi0xLjc=';this.onload();}},
  webkit:{messageHandlers:{omnigoReport:{postMessage:async message=>{requests.push(message);return response;}}}}};
 context.HTMLAnchorElement.prototype.click=function(){};
 context.window=context;context.top=frame?{}:context;
 vm.runInNewContext(source.replaceAll('__OMNIGO_PORTAL_ORIGIN__','https://portal.omnigoglobal.com'),context);
 return {context,requests};
}
test('mağaza kimlikleri ayrı, API 36 ve dış APK güncellemesi kapalı',()=>{
 const client=storeChannel('client'),admin=storeChannel('admin');
 assert.notEqual(client.applicationId,admin.applicationId);
 assert.notEqual(client.host,admin.host);
 for(const channel of [client,admin]){
  assert.equal(channel.targetApi,36);
  assert.match(javaConfiguration(channel),/ALLOW_DIRECT_UPDATES = false/);
  assert.ok(channel.versionCode>1);
 }
 assert.throws(()=>storeChannel('unknown'));
});
test('iOS dosya köprüsü başka kökene veya alt çerçeveye açılmaz',()=>{
 for(const options of [{origin:'https://evil.test'},{origin:'http://portal.omnigoglobal.com'},{origin:'https://yonetim.omnigoglobal.com'},{frame:true}]){
  assert.equal(createIOS(options).context.__OMNIGO_SAVE_REPORT__,undefined);
  assert.equal(createIOS(options).context.__OMNIGO_SET_LANGUAGE__,undefined);
 }
});

test('iOS dil komutu yalnız desteklenen dili aktarır ve rapor içeriğini değiştirmez',async()=>{
 const {context,requests}=createIOS();
 for(const language of ['tr','en','de'])await context.__OMNIGO_SET_LANGUAGE__(language);
 for(const language of ['fr','en;save_report',null,{}])await context.__OMNIGO_SET_LANGUAGE__(language);
 assert.equal(requests.length,3);
 assert.deepEqual(JSON.parse(JSON.stringify(requests)),['tr','en','de'].map(language=>({command:'set_language',data:{language}})));
 await context.__OMNIGO_SAVE_REPORT__('Eskişehir Teslimat Evrakları.pdf','JVBERi0xLjc=');
 assert.equal(requests[3].contents,'JVBERi0xLjc=');
 assert.equal(requests[3].filename,'Eskişehir Teslimat Evrakları.pdf');
});
test('Türkçe dosya adı ve kaydetme sonucu yerel köprüye aktarılır',async()=>{
 const {context,requests}=createIOS();
 assert.equal(await context.__OMNIGO_SAVE_REPORT__('Eskişehir Teslimat Evrakları.pdf','JVBERi0xLjc='),'saved');
 assert.equal(requests[0].filename,'Eskişehir Teslimat Evrakları.pdf');
 assert.equal(requests[0].command,'save_report');
 await assert.rejects(()=>context.__OMNIGO_SAVE_REPORT__('rapor.pdf','A'.repeat(44739245)),/büyük/);
});
test('iOS paylaşım dosya türü ve boyutunu kontrol eder, iptali doğru döndürür',async()=>{
 const {context,requests}=createIOS({response:'cancelled'});
 assert.equal(context.navigator.canShare({files:[{name:'report.exe',size:5}]}),false);
 assert.equal(context.navigator.canShare({files:[{name:'report.pdf',size:33554433}]}),false);
 assert.equal(context.navigator.canShare({files:[{name:'report.pdf',size:0}]}),false);
 assert.equal(context.navigator.canShare({files:[{name:'a.pdf',size:10},{name:'b.pdf',size:10}]}),false);
 const file={name:'Eskisehir Teslimat Evraklari.pdf',size:9};
 assert.equal(context.navigator.canShare({files:[file]}),true);
 await assert.rejects(()=>context.navigator.share({files:[file]}),error=>error.name==='AbortError');
 assert.equal(requests[0].command,'share_report');
});

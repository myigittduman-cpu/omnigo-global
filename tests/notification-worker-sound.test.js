import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {MessageChannel} from 'node:worker_threads';
const source=fs.readFileSync(new URL('../public/push-worker.js',import.meta.url),'utf8');
async function deliver(response,{sound=true,visible=true}={}){
 const notices=[],messages=[],events={};
 const tab={url:'https://portal.omnigoglobal.com/',visibilityState:visible?'visible':'hidden',focused:true,postMessage(data,ports){messages.push(data);if(response!==null)ports[0].postMessage({played:response});ports[0].close();}};
 vm.runInNewContext(source,{URL,Date,MessageChannel,setTimeout,clearTimeout,self:{location:{origin:'https://portal.omnigoglobal.com'},addEventListener:(type,fn)=>events[type]=fn,clients:{matchAll:async()=>[tab]},registration:{showNotification:async(title,options)=>notices.push({title,...options})}}});
 let pending;events.push({data:{json:()=>({title:'Eski başlık',body:'Eski gövde',presentation:{title:'Omnigo Global İş Ortağım',body:'Vizyona Beyaz Eşya; Talebiniz sonuçlandı.'},sound})},waitUntil:p=>pending=p});await pending;
 return {notice:notices[0],messages};
}
test('Açık sayfa özel tonu çalınca sistem sesi ikinci kez çalmaz',async()=>{
 const {notice,messages}=await deliver(true);assert.equal(notice.title,'Omnigo Global İş Ortağım');assert.equal(notice.body,'Vizyona Beyaz Eşya; Talebiniz sonuçlandı.');assert.equal(notice.silent,true);assert.equal(messages.length,1);
});
test('Kapalı, ses kilitli veya yanıt vermeyen sayfada sistem sesi korunur',async()=>{
 for(const response of [false,null])assert.equal((await deliver(response)).notice.silent,false);
 const hidden=await deliver(true,{visible:false});assert.equal(hidden.notice.silent,false);assert.equal(hidden.messages.length,0);
});
test('Sessiz olay özel tonu da çalmaz',async()=>{const result=await deliver(true,{sound:false});assert.equal(result.notice.silent,true);assert.equal(result.messages.length,0);});

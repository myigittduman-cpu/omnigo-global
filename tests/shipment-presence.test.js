import test from 'node:test';
import assert from 'node:assert/strict';
import {bindShipmentPresence} from '../public/shipment-presence.js';

async function environment(action){
 const keys=['document','window','MutationObserver','fetch','setTimeout','clearTimeout'];
 const saved=Object.fromEntries(keys.map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
 let reply={editing:false},fail=false,refreshes=0,dialog=null,connected=true;
 const requests=[],model={draftChanges:[],error:false},root={get isConnected(){return connected;}};
 const document={hidden:false,addEventListener(){},querySelector(){return null;},body:{append(value){dialog=value;}},createElement(){const p={textContent:''};return {setAttribute(){},addEventListener(){},querySelector(){return p;},showModal(){this.open=true;},close(){this.open=false;},remove(){dialog=null;}};}};
 const mocks={document,window:{addEventListener(){}},MutationObserver:class{observe(){}disconnect(){}},setTimeout(){return 1;},clearTimeout(){},fetch:async(url,options)=>{requests.push(options.body?JSON.parse(options.body):{action:'read'});if(fail)throw Error('offline');return {ok:true,json:async()=>reply};}};
 for(const [key,value]of Object.entries(mocks))Object.defineProperty(globalThis,key,{configurable:true,value});
 try{await action({model,requests,context:{root,model,current:()=>connected,endpoint:()=>'/shipments/editing',refresh:async()=>{refreshes++;}},setReply(value){reply=value;},setFail(value){fail=value;},get dialog(){return dialog;},get refreshes(){return refreshes;}});}
 finally{for(const [key,descriptor]of Object.entries(saved)){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else delete globalThis[key];}}
}
const settle=()=>new Promise(resolve=>setImmediate(resolve));
test('Yönetici düzenlerken müşteri bekler, hata sırasında kilit kalır, güncel liste alınınca açılır',async()=>environment(async e=>{
 const presence=bindShipmentPresence({...e.context,isAdmin:false});await settle();
 e.setReply({editing:true});await presence.check();assert.equal(e.model.presenceBlocked,true);assert.ok(e.dialog.open);
 e.setFail(true);await presence.check();assert.ok(e.dialog.open);
 e.setFail(false);e.setReply({editing:false});await presence.check();assert.equal(e.refreshes,1);assert.equal(e.dialog,null);assert.equal(e.model.presenceBlocked,false);
}));
test('Müşterinin açık taslağı yönetici beklemesi sonrasında silinmez',async()=>environment(async e=>{
 const presence=bindShipmentPresence({...e.context,isAdmin:false});await settle();
 e.model.draftChanges=[{change:{type:'update',id:12}}];e.setReply({editing:true});await presence.check();e.setReply({editing:false});await presence.check();
 assert.equal(e.model.draftChanges.length,1);assert.equal(e.refreshes,0);assert.equal(e.dialog,null);assert.match(e.model.message,/Taslağınız korundu/);
}));
test('Yönetici kilidi kaydetme/çıkışta bırakılır, her yeni düzenleme ayrı sekme anahtarı kullanır',async()=>environment(async e=>{
 const presence=bindShipmentPresence({...e.context,isAdmin:true});await presence.acquire();await presence.acquire();await presence.release();await presence.acquire();await presence.release();
 assert.deepEqual(e.requests.map(r=>r.action),['renew','renew','release','renew','release']);assert.equal(e.requests[0].token,e.requests[2].token);assert.notEqual(e.requests[0].token,e.requests[3].token);
}));

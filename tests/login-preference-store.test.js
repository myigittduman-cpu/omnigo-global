import test from 'node:test';
import assert from 'node:assert/strict';
import {readRememberedLogin,writeRememberedLogin,clearRememberedLogin} from '../public/login-preference-store.js';
const memory=()=>{const values=new Map();return {getItem:key=>values.get(key)||null,setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};};
for(const mode of ['username','email','auto'])test(`Beni hatırla: ${mode}, güncelleme ve kaldırma`,()=>{
 const storage=memory(),identity={mode,value:mode==='email'?'qa@example.test':'qa.kullanici'};
 writeRememberedLogin({...identity,password:'never-store'},storage);
 assert.deepEqual(readRememberedLogin(storage),identity);
 assert(!storage.getItem('omnigo.remembered-login.v2').includes('never-store'));
 clearRememberedLogin(storage);assert.equal(readRememberedLogin(storage),null);
});
test('Eski e-posta kaydı korunur, kullanıcı adına geçildiğinde eski kayıt kaldırılır',()=>{
 const storage=memory();storage.setItem('omnigo.remembered-email.v1','qa@example.test');
 assert.deepEqual(readRememberedLogin(storage),{mode:'email',value:'qa@example.test'});
 writeRememberedLogin({mode:'username',value:'qa.user'},storage);
 assert.equal(storage.getItem('omnigo.remembered-email.v1'),null);
 assert.equal(readRememberedLogin(storage).mode,'username');
 storage.setItem('omnigo.remembered-login.v2','invalid JSON');assert.equal(readRememberedLogin(storage),null);
});
test('Depolama izni olmayan cihaz giriş ekranını bozmaz',()=>{
 const original=Object.getOwnPropertyDescriptor(globalThis,'localStorage');
 Object.defineProperty(globalThis,'localStorage',{configurable:true,get(){throw new Error('blocked');}});
 try{assert.equal(readRememberedLogin(),null);assert.doesNotThrow(()=>writeRememberedLogin({mode:'username',value:'qa.user'}));assert.doesNotThrow(()=>clearRememberedLogin());}
 finally{if(original)Object.defineProperty(globalThis,'localStorage',original);else delete globalThis.localStorage;}
});

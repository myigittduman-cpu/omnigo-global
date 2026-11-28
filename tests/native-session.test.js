import test from 'node:test';import assert from 'node:assert/strict';
import {restoreNativeSession,syncNativeSession,forgetNativeSession} from '../public/native-session.js';
test('Windows oturum köprüsü tarayıcıda yokken davranış değişmez; eşzamanlı açılış tek kez geri yükler',async()=>{
 assert.equal(await restoreNativeSession(),false);
 const calls=[];globalThis.window={__OMNIGO_PERSISTENT_SESSION__:async operation=>{calls.push(operation);return true;}};
 try{
  await Promise.all([restoreNativeSession(),restoreNativeSession()]);assert.deepEqual(calls,['restore']);
  await syncNativeSession('/account/session','PUT',{keepSession:true});
  await syncNativeSession('/account/session','PUT',{keepSession:false});
  await syncNativeSession('/logout','POST',{});await forgetNativeSession();
  await syncNativeSession('/shipments','GET',{});
  assert.deepEqual(calls,['restore','capture','clear','clear','clear']);
  window.__OMNIGO_PERSISTENT_SESSION__=async()=>false;
  await assert.rejects(syncNativeSession('/account/session','PUT',{keepSession:true}),/kaydedilemedi/);
 }finally{delete globalThis.window;}
});

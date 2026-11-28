import test from 'node:test';
import assert from 'node:assert/strict';
import {loadPortalIdentity,canResumePortalIdentity} from '../public/portal-bootstrap.js';

test('Kalıcı ve geçerli oturum giriş ekranını atlar; güncelleme ve geçici oturum sınırı korunur',async()=>{
 for(const role of ['admin','client']){
  const identity={user:{id:1,role},keepSession:true};
  assert(await canResumePortalIdentity(identity,{check:async()=>({kind:'ready'})}));
  assert(!await canResumePortalIdentity({...identity,keepSession:false}));
  assert(!await canResumePortalIdentity(identity,{check:async()=>({kind:'web'})}));
  assert(!await canResumePortalIdentity(identity,{check:async()=>{throw Error('Bağlantı yok');}}));
 }
 assert(!await canResumePortalIdentity(null));
});
test('Durum ve oturum sorguları birbirini beklemez; mevcut kimlik korunur',async()=>{
 const calls=[],resolvers={};
 const pending=loadPortalIdentity(path=>{calls.push(path);return new Promise(resolve=>resolvers[path]=resolve);});
 assert.deepEqual(calls,['/status','/me']);
 resolvers['/me']({user:{id:3,role:'admin'},csrf:'sample'});resolvers['/status']({needsSetup:false,portalRole:'admin'});
 assert.deepEqual((await pending).identity.user,{id:3,role:'admin'});
});
test('Oturum yoksa normal giriş; ilk kurulumda kurulum yolu korunur',async()=>{
 const request=setup=>path=>path==='/status'?Promise.resolve({needsSetup:setup}):Promise.reject(Object.assign(new Error('Oturum yok'),{status:401}));
 assert.equal((await loadPortalIdentity(request(false))).identity,null);
 assert.equal((await loadPortalIdentity(request(true))).status.needsSetup,true);
});
test('Bağlantı ve sunucu hataları giriş ekranı gibi gösterilmez',async()=>{
 const failure=Object.assign(new Error('Sunucuya erişilemiyor'),{status:503});
 await assert.rejects(loadPortalIdentity(path=>path==='/status'?Promise.resolve({needsSetup:false}):Promise.reject(failure)),error=>error===failure);
});

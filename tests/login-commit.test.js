import test from 'node:test';
import assert from 'node:assert/strict';
import {commitRememberedLogin} from '../public/login-commit.js';

test('Cihaz kasası kayıt bitmeden giriş formu bırakılmaz',async()=>{
 let resolve,completed=false;
 const control={rememberCredentials:()=>new Promise(done=>{resolve=done;})};
 const result=commitRememberedLogin({dataset:{credentialStorage:'device-vault'}},control,{id:1}).then(value=>{completed=true;return value;});
 await Promise.resolve();assert.equal(completed,false);resolve(true);assert.equal(await result,true);
});
test('Tarayıcı kasa izin penceresi oturum açmayı bekletmez',async()=>{
 const result=await commitRememberedLogin({dataset:{credentialStorage:'browser-vault'}},{rememberCredentials:()=>new Promise(()=>{})},{id:1});
 assert.equal(result,null);
});
test('Cihaz kayıt hatası ve zaman aşımı giriş işleyişini kilitlemez',async()=>{
 const form={dataset:{credentialStorage:'device-vault'}};
 assert.equal(await commitRememberedLogin(form,{rememberCredentials:async()=>{throw new Error('Kasa kapalı');}},{id:1}),false);
 assert.equal(await commitRememberedLogin(form,{rememberCredentials:()=>new Promise(()=>{})},{id:1},{timeoutMs:15}),false);
});

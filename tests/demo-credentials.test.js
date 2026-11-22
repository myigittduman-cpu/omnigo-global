import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {openDatabase} from '../src/database.js';
import {seedDemo,resolveDemoPassword} from '../src/demo.js';
import {verifyPassword} from '../src/security.js';
import {portalLoginView} from '../public/portal-login-view.js';
import {adminLoginView} from '../public/admin-login-view.js';

const legacyPassword='Omnigo-Demo-'+'2026!';

test('demo şifresi ortam değişkeni yoksa rastgele üretilir', ()=>{
 const a=resolveDemoPassword({}),b=resolveDemoPassword({});
 assert.equal(a.generated,true);assert.notEqual(a.password,b.password);
 assert.ok(a.password.length>=16);assert.notEqual(a.password,legacyPassword);
});

test('OMNIGO_DEMO_PASSWORD kullanılır, kısa değer reddedilir', ()=>{
 assert.deepEqual(resolveDemoPassword({OMNIGO_DEMO_PASSWORD:'Yeterince-uzun-1'}),{password:'Yeterince-uzun-1',generated:false});
 assert.throws(()=>resolveDemoPassword({OMNIGO_DEMO_PASSWORD:'kisa'}),/en az 12/);
});

test('seedDemo üretilen şifreyle hesap açar, eski sabit şifre çalışmaz', async()=>{
 const dir=await fs.mkdtemp(path.join(os.tmpdir(),'omnigo-demo-'));
 const db=openDatabase(path.join(dir,'demo.sqlite'));
 try{
  const credential=await seedDemo(db,{env:{}});
  for(const email of ['yonetici@ornek.test','musteri@ornek.test']){
   const {password}=db.prepare('SELECT password FROM users WHERE email=?').get(email);
   assert.equal(await verifyPassword(credential.password,password),true);
   assert.equal(await verifyPassword(legacyPassword,password),false);
  }
  assert.equal(await seedDemo(db,{env:{}}),null);
 }finally{db.close();await fs.rm(dir,{recursive:true,force:true});}
});

test('giriş ekranlarındaki demo notu şifre içermez', ()=>{
 for(const markup of [portalLoginView({role:'admin',demo:true}),portalLoginView({role:'client',demo:true}),adminLoginView({demo:true})]){
  assert.match(markup,/demo-note/);assert.doesNotMatch(markup,/Omnigo-Demo-2026|Şifre:/);
 }
});

import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {orderAlertGroups,orderNotificationPresentation} from '../public/order-alert-model.js';
import {mobileChannel} from '../android/mobile/configuration.mjs';
test('Her sipariş kendi tutarı ve ayrıntı bağlantısıyla kalır',()=>{
 const one={id:1,noticeId:1,type:'order',provider:'hepsiburada',companyId:1,company:'Vizyona Beyaz Eşya',amountCents:1234567};
 const groups=orderAlertGroups([one,one,{...one,id:2,noticeId:2,amountCents:9900}]);
 assert.equal(groups.length,2);assert.ok(groups.every(g=>g.count===1));
 assert.deepEqual(orderNotificationPresentation(groups[0]),{title:'Omnigo Global Yönetici Portalı',body:'Vizyona; Hepsiburada’dan 12.345,67₺ tutarında yeni bir siparişiniz var.'});
 assert.match(orderNotificationPresentation(groups[1]).body,/99,00₺/);
 assert.equal(orderNotificationPresentation({...one,type:'operation',events:['cancelled']}).body,'Vizyona; Sipariş iptal edildi.');
 assert.doesNotMatch(orderNotificationPresentation({...one,amountCents:null,count:1}).body,/0,00₺/);
});
test('Onaylı kısa kurulum adları tüm platformlarda aynıdır; bildirim başlığı markayı korur',()=>{
 for(const [role,name] of [['admin','OG Yönetici Portalı'],['client','OG İş Ortağım']]){
  const channel=mobileChannel(role),manifest=JSON.parse(fs.readFileSync(new URL(`../public/${role}.webmanifest`,import.meta.url)));
  assert.equal(manifest.name,name);assert.equal(manifest.short_name.replaceAll('\u00a0',' '),name);assert.equal(channel.launcherName.replaceAll('\u00a0',' '),name);assert.equal(channel.title,name);
 }
 const desktop=JSON.parse(fs.readFileSync(new URL('../desktop/tauri.conf.json',import.meta.url)));assert.equal(desktop.productName,'OG İş Ortağım');
});

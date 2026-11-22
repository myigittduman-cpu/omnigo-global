import test from 'node:test';
import assert from 'node:assert/strict';
import {notificationList,notificationBell,notificationControls} from '../public/notification-view.js';
import {portalNavigation} from '../public/portal-navigation.js';
import {clearWorkspace} from '../public/workspace-context.js';

test('Yönetici zilinde alıcı ve doğrulanmış sipariş tutarı isteğe bağlıdır',()=>{
 const item={id:5,type:'order',provider:'n11',company:'Deneme',buyerName:'A < B',amountCents:123456};
 const list=value=>notificationList({items:[value]},'','all','all',true);
 assert.match(list(item),/A &lt; B · .*1\.234,56/);
 assert.match(list({...item,amountCents:0}),/0,00/);
 assert.doesNotMatch(list({...item,amountCents:null}),/0,00/);
 assert.doesNotMatch(notificationList({items:[item]}),/A &lt; B|1\.234,56/);
});

test('Yeni çalışma alanı tüm bildirimleri gösterir; son 10 kayıt okununca listede kalır',()=>{
 const state={};clearWorkspace(state);assert.equal(state.notificationFilter,'all');
 assert.match(notificationList({items:[]}),/Henüz bildirim yok/);
 const items=Array.from({length:12},(_,index)=>({type:'order',id:index+1,provider:'n11',read:index%2===0,createdAt:'2026-10-30T10:00:00Z'}));
 const html=notificationList({items});
 assert.equal((html.match(/data-notification="order:/g)||[]).length,10);
 assert.match(html,/is-read/);assert.match(html,/is-unread/);assert.doesNotMatch(html,/order:11|order:12/);
});
test('Soru bildirimleri gizlenir; destek talepleri güvenli metinle gösterilir',()=>{
 const html=notificationList({items:[
  {type:'marketplace',id:1,provider:'koctas',preview:'GİZLİ MESAJ'},
  {type:'support',id:2,company:'Vizyona <img onerror=alert(1)>'},
  {type:'job',id:3,company:'SİSTEM İŞLEMİ'}]});
 assert.doesNotMatch(html,/Koçtaş’tan|müşterinizin sorusu var|marketplace:1/);
 assert.match(html,/Vizyona &lt;img/);assert.match(html,/destek talebi oluşturdu/);
 assert.doesNotMatch(html,/GİZLİ MESAJ|SİSTEM İŞLEMİ|<img onerror/);
 assert.equal((html.match(/data-notification=/g)||[]).length,1);
});
test('Zil hesap rolüne göre kategorileri gösterir; boş ve hata durumları ayrıdır',()=>{
 assert.match(notificationBell({user:{role:'client'}}),/Destek Bildirimleri/);
 assert.doesNotMatch(notificationBell({user:{role:'client'}}),/Bağlantı Bildirimleri|Pazaryeri Bildirimleri/);
 assert.match(notificationBell({user:{role:'admin'}}),/aria-expanded="false"/);
 assert.match(notificationList({items:[]},'','all'),/Henüz bildirim yok/);
 const html=notificationList(null,'SQL hatası');
 assert.match(html,/Tekrar dene/);assert.doesNotMatch(html,/Henüz bildirim yok|SQL hatası/);
});
test('Soru bölümü menülerden çıkar; mobil menüde siparişler görünür',()=>{
 const names={overview:'Genel bakış',customers:'Müşteriler',orders:'Siparişler',inbox:'Sorular ve mesajlar',support:'Destek talepleri'};
 for(const mobile of [true,false]){
  const html=portalNavigation(names,{user:{role:'admin'},view:'overview'},{mobile});
  assert.doesNotMatch(html,/data-nav="(?:notifications|inbox)"|Sorular ve mesajlar/);
  assert.match(html,/data-nav="orders"/);
 }
});
test('Bildirimlerde okuma filtreleri ve geri alma kaldırılır; temizleme kapsamı açıklanır',()=>{
 const html=notificationControls({notificationFilter:'read',notificationData:{counts:{all:40,unread:30},undoId:'a'.repeat(48)}});
 assert.doesNotMatch(html,/data-notification-filter|Bu bölümü okundu işaretle|Geri al/);
 assert.match(html,/Bu bölümü temizle/);
 assert.match(notificationControls({notificationConfirmDelete:true}),/Kaynak kayıtlar korunur/);
 assert.match(notificationList({items:[]},'','unread'),/Okunmamış bildirim yok/);
 assert.match(notificationList({items:[]},'','read'),/Okunan bildirim yok/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {workspaceNavigation} from '../public/workspace-navigation.js';
import {portalNavigation} from '../public/portal-navigation.js';

const names={overview:'Genel bakış',customers:'Müşteriler',channels:'Panel satışları',orders:'Siparişler',analytics:'Analiz ve raporlar',connections:'Bağlantı kontrolü',deliveries:'Teslimat Evrakları',shipments:'Şehir Dışı Sevkiyat',payments:'Aktif hakedişler',tasks:'Aktif çalışmalar',reports:'Raporlarım',support:'Destek taleplerim',inbox:'Sorular ve mesajlar',unexpected:'Yetkisiz bölüm'};
const ids=html=>[...html.matchAll(/data-nav="([^"]+)"/g)].map(m=>m[1]);

test('Müşteri menüsü kendi teslimat listesine ve sevkiyatına erişir; yönetici araçları kapalıdır',()=>{
 const html=workspaceNavigation(names,{user:{id:2,role:'client'},view:'shipments'});
 assert.deepEqual(ids(html),['tasks','deliveries','shipments','analytics','payments','channels','reports','support']);
 assert.doesNotMatch(html,/data-edit-navigation|<details|data-nav="(?:overview|customers|orders|connections|inbox|unexpected)"/);
 assert.match(html,/data-nav="shipments"[^>]*aria-current="page"/);
 const clientNames=Object.fromEntries(Object.entries(names).filter(([id])=>ids(html).includes(id)));
 const mobile=portalNavigation(clientNames,{user:{id:2,role:'client'},view:'shipments'},{mobile:true});
 assert.deepEqual(ids(mobile),['channels','analytics','payments']);assert.match(mobile,/data-more-menu[^>]*aria-controls="workspace-directory"/);
 assert.match(portalNavigation(clientNames,{user:{id:2,role:'client'},view:'shipments'}),/data-nav="deliveries"/);
});
test('Yönetici menüsü ortak sırayı kullanır; eski düzenleme düğmesi gösterilmez',()=>{
 const html=workspaceNavigation(names,{user:{id:1,role:'admin'},view:'reports'});
 assert.equal(ids(html).length,12);assert.equal(new Set(ids(html)).size,12);
 assert.ok(ids(html).includes('overview'));assert.ok(!ids(html).includes('channels'));
 for(const id of ['customers','orders','deliveries','connections','reports'])assert.ok(ids(html).includes(id));
 assert.doesNotMatch(html,/data-edit-navigation/);assert.match(html,/workspace-directory-grid/);assert.doesNotMatch(html,/workspace-secondary-links|workspace-primary-links|<details|<summary|data-workspace-focus-tab/);assert.match(html,/data-nav="reports"[^>]*aria-current="page"/);
 assert.deepEqual(ids(html),['overview','customers','orders','tasks','inbox','deliveries','shipments','analytics','payments','reports','support','connections']);
 assert.doesNotMatch(html,/data-nav="unexpected"/);
});
test('Bilinmeyen rol için gezinme üretilmez; başlık metni HTML olarak çalışmaz',()=>{
 assert.equal(workspaceNavigation(names,{user:{role:'guest'},view:'channels'}),'');
 const html=workspaceNavigation({reports:'<img src=x onerror=alert(1)>'},{user:{role:'client'},view:'reports'});
 assert.match(html,/&lt;img/);assert.doesNotMatch(html,/<img src=x/);
});

test('Masaüstü düzeni yalnız gerçekten görünen bağlantıları sayar',()=>{
 const pages=['inventory','deliveries','shipments','orders'];
 const operationNames={orders:'Siparişler',inventory:'Stok Yönetimi',shipments:'Şehir Dışı Sevkiyat',deliveries:'Teslimat Evrakları'};
 for(let mask=0;mask<2**pages.length;mask++){
  const visible=pages.filter((_,index)=>mask&(1<<index));
  const access={pages:Object.fromEntries(pages.map(page=>[page,'shown'])),grants:Object.fromEntries(pages.map(page=>[page,{view:visible.includes(page)}]))};
  const html=workspaceNavigation(operationNames,{user:{role:'client'},view:'inventory',data:{workspaceAccess:access}});
  assert.deepEqual(ids(html),visible);
  if(!visible.length)assert.doesNotMatch(html,/data-menu-group="operations"/);
  else assert.ok(html.includes('data-menu-count="'+visible.length+'"'));
 }
});

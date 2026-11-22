import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import crypto from 'node:crypto';
import {api,upload} from '../public/demo/api.js';
import {company,providers,today} from '../public/demo/data.js';
test('Demo gerçek ağ, oturum veya yönetici verisi kullanmadan müşteri ekranlarını besler',async()=>{
 const original=globalThis.fetch;globalThis.fetch=()=>{throw new Error('Ağ çağrısı yasak');};
 try{
  const me=await api('/me');assert.equal(me.user.role,'client');assert.equal(me.csrf,'');
  assert.deepEqual((await api('/companies')).map(c=>c.id),[company.id]);
  const dash=await api('/dashboard');assert.deepEqual(dash.channels.map(c=>c.name),providers);assert.equal(dash.tasks.length,3);
  const report=await api('/analytics/report?from=2026-10-01&to=2026-10-13&channel=n11');assert.equal(report.series.length,1);assert.equal(report.series[0].days.length,13);
  assert.equal(report.totals.netCents,report.series[0].days.reduce((sum,d)=>sum+d.netCents,0));
  const payments=await api('/payments/calendar?from=2026-10-01&to=2026-10-31');assert.equal(payments.items.length,12);
  assert.ok(dash.marketplaceSales.currentMonthTurnoverCents>0);
  assert.deepEqual(dash.upcoming,payments.upcoming);
  assert.ok(dash.upcoming.periodItems.length>=4);
  assert.ok(dash.upcoming.periodItems.every(row=>row.date>=dash.upcoming.today&&row.amountCents>0));
  assert.equal((await api('/support/tickets/1')).messages.length,2);
 }finally{globalThis.fetch=original;}
});
test('Demo yazma, evrak yükleme, başka müşteri ve bilinmeyen yolları kapatır',async()=>{
 for(const [route,method] of [['/logout','POST'],['/shipments/1000','PUT'],['/support/tickets','POST'],['/admin/users','GET'],['/dashboard?company=1','GET'],['https://outside.invalid/status','GET']])await assert.rejects(api(route,method,{}),e=>e.status===403);
 await assert.rejects(upload('/shipments/1000/documents',{}),e=>e.status===403);
 const first=await api('/companies');first[0].name='Değiştirildi';assert.equal((await api('/companies'))[0].name,company.name);
});
test('Demo sevkiyat araması, şehir filtresi, sayfalama ve dışa aktarım aynı örnek kayıtlarda çalışır',async()=>{
 const base='/shipments?company='+company.id+'&provider=n11&month='+today().slice(0,7);
 const first=await api(base),second=await api(base+'&page=1');assert.equal(first.total,18);assert.equal(first.items.length,10);assert.equal(second.items.length,8);assert.notEqual(first.items[0].id,second.items[0].id);
 const city=await api(base+'&city=Ankara');assert.equal(city.items.length,3);assert.ok(city.items.every(x=>x.province==='Ankara'));
 const filtered=await api(base.replace('/shipments?','/shipments/search?'),'POST',{filters:{rowStatus:'shipped'}});assert.ok(filtered.items.every(x=>x.rowStatus==='shipped'));
 const all=await api(base.replace('/shipments?','/shipments/export?'),'POST',{});assert.equal(all.items.length,18);
});
test('Demo import eşlemesi yalnızca örnek API kullanır; CSP üretim API isteklerini açmaz',async()=>{
 const html=await fs.readFile(new URL('../public/demo/index.html',import.meta.url),'utf8'),headers=await fs.readFile(new URL('../public/demo/.htaccess',import.meta.url),'utf8');
 const map=html.match(/<script type="importmap">(.*?)<\/script>/s)[1];assert.equal(JSON.parse(map).imports['/api.js'],'/demo/api.js');
 assert.ok(headers.includes('sha256-'+crypto.createHash('sha256').update(map).digest('base64')));
 const connect=headers.match(/connect-src ([^;]+)/)[1];assert.ok(!connect.includes("'self'"));assert.ok(!connect.includes('/api'));assert.ok(headers.includes("worker-src 'none'"));assert.ok(!html.includes('install.js'));
});

test('Güncel müşteri demosu stok, sipariş, iade, teslimat ve ortak görünümü yalnız örnek verilerle açar',async()=>{
 const dash=await api('/dashboard');assert.equal(dash.workspaceAccess.pages.inventory,'shown');assert.equal(dash.workspaceAccess.grants.inventory.view,true);assert.notEqual(dash.workspaceAccess.grants.inventory.edit,true);
 assert.equal((await api('/account/preferences')).preferences.language,'tr');
 const inventory=await api('/inventory');assert.ok(inventory.items.length);assert.equal(inventory.settings.mode,'both');assert.ok(inventory.items[0].marketplaceStocks.length);
 assert.equal((await api('/inventory/history')).items[0].actor_name,'Örnek operasyon ekibi');
 const orders=await api('/orders');assert.equal(orders.items.length,18);assert.ok((await api('/orders/'+orders.items[0].id)).packages.length);
 assert.equal((await api('/returns')).items.length,1);assert.ok((await api('/delivery/orders?city=Ankara')).items.every(item=>item.city==='Ankara'));
 for(const route of ['/inventory/commands','/account/preferences','/delivery/pdf-data'])await assert.rejects(api(route,'POST',{}),error=>error.status===403);
 const generated=await fs.readFile(new URL('../public/demo/app.js',import.meta.url),'utf8'),source=await fs.readFile(new URL('../public/app.js',import.meta.url),'utf8');
 const expected=source.replace("else if(me.user.role==='admin'||me.user.role==='client'||loginUpdates)loginScreen(false,'',me.user);","else if(false)loginScreen(false,'',me.user);").replaceAll("from './","from '/").replaceAll("import './","import '/");
 assert.equal(generated.split('\n').slice(1).join('\n'),expected,'Demo ortak kabuktan kopmamalı');
});

test('Demo filtreleri örnek kayıtlara uygulanır; sipariş ayrıntısı seçili pazaryerini korur',async()=>{
 const inventory=await api('/inventory'),category=inventory.categories[0];
 assert.equal((await api('/inventory?category='+category.value)).total,1);
 assert.equal((await api('/inventory?category=missing')).total,0);
 assert.equal((await api('/inventory?filter=removed')).total,0);
 const orders=await api('/orders'),provider=orders.items[1].provider;
 const filtered=await api('/orders?provider='+provider+'&page=99');
 assert.ok(filtered.items.length);assert.ok(filtered.items.every(item=>item.provider===provider));assert.equal(filtered.page,0);
 const detail=await api('/orders/'+orders.items[1].id);assert.notEqual(detail.provider,'n11');assert.equal(detail.packages[0].provider,detail.provider);
 assert.equal((await api('/orders?from=2000-01-01&to=2000-01-02')).total,0);
 assert.equal((await api('/returns?search=bulunmayan')).total,0);
 assert.equal((await api('/returns?search=ORNEK-TALEP')).total,1);
 assert.equal((await api('/inventory/history?productKey='+inventory.items[1].key)).items.length,0);
 assert.equal((await api('/inventory/history?from=2000-01-01&to=2000-01-02')).items.length,0);
});

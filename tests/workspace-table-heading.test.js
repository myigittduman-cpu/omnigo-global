import assert from 'node:assert/strict';
import test from 'node:test';
import {workspaceTableHeading} from '../public/workspace-table-heading.js';
import {deliveryTableScope} from '../public/delivery-heading.js';
import {deliveryContent,deliveryView} from '../public/delivery-view.js';

const title = '<span class="eyebrow">Yönetici</span><h1>Başlık</h1>';
const first = {id:12,name:'Mağaza <A>',hasLogo:true,brandingVersion:3};
const other = {id:18,name:'Mağaza B',hasLogo:true,brandingVersion:4};
const state = {company:12,companies:[first,other],data:{company:other}};

test('Çıktı tablolarının başlıkları yalnızca seçili mağazanın oval logosunu kullanır', () => {
 for(const view of ['overviewSales','overviewTop','channels','orders','shipments','deliveries','cityProducts','shipmentInvoices','financeProfitability','analytics','fileCenter','inbox','reports']){
  const heading = workspaceTableHeading({...state,view},title);
  assert.match(heading, /fit=payment&amp;company=12&amp;v=3/);
  assert.match(heading, /width="600" height="240"/);
  assert.match(heading, /Mağaza &lt;A&gt; logosu/);
  assert.ok(heading.indexOf('<img') < heading.indexOf('<h1>'));
  assert.doesNotMatch(heading, /company=18/);
 }
});

test('Mağaza sayfaları ve sonradan izin verilen yeni bölüm ortak başlığı kullanır', () => {
 for(const view of ['overview','payments','customers','support','connections','inventory','tasks','futureStoreSection'])
  assert.match(workspaceTableHeading({...state,view}, title),/data-company-logo="12"/);
 for(const unsafe of [workspaceTableHeading({...state,view:'reports'},title,false),
  workspaceTableHeading({...state,company:99,view:'orders'},title),
  workspaceTableHeading({...state,user:{role:'client',companyId:18}},title)]){
  assert.match(unsafe,/table-heading-copy/);assert.doesNotMatch(unsafe,/<img|data-company-logo|src=/);
 }
});

test('Logo yoksa resim istenmez, metin aynı alanda kalır ve logo yerinde güncellenebilir', () => {
 const heading = workspaceTableHeading({...state,view:'deliveries',data:{company:{...first,hasLogo:false}}},title);
 assert.match(heading,/data-company-logo="12" hidden/);
 assert.doesNotMatch(heading,/src=/);
 assert.ok(heading.includes(title));
});

test('Gecikmiş marka bilgisi aynı mağazanın en yeni doğrulanmış kaydından tamamlanır',()=>{
 for(const active of [{id:12,name:first.name},{...first,hasLogo:false,brandingVersion:2}])
  assert.match(workspaceTableHeading({...state,data:{company:active}},title),/company=12&amp;v=3/);
});

test('Teslimat kapsamı tablonun hemen üstünde, ortak sayfa başlığını çoğaltmadan açılır', () => {
 const model={availableCities:['Ankara','Kayseri'],shippingMode:'nationwide',city:'all',queue:'all',readOnly:true,selected:new Set()};
 const toolbar=deliveryTableScope(model);
 assert.match(toolbar,/Tüm Türkiye/);assert.match(toolbar,/data-city="all"/);assert.match(toolbar,/data-city-filter-form/);
 const body=deliveryView(model);
 assert.ok(body.indexOf(toolbar)>=0 && body.indexOf(toolbar)<body.indexOf('class="delivery-orders"'));
 for(const markup of [deliveryContent(state),body,deliveryView({...model,shippingMode:'regional'})])assert.doesNotMatch(markup,/<h1|class="page-heading/);
 assert.equal(deliveryTableScope({...model,shippingMode:'regional'}),'');
});

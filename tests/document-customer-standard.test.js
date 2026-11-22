import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {deliveryExportCompany,companyLogoSource,companyExportLogo,drawCompanyPdfLogo} from '../public/company-export-brand.js';
import {createDeliveryWorkbook} from '../public/delivery-workbook.js';
import {Workbook} from '../public/vendor/report-xlsx-070.js';

test('Her müşteri kendi logo kaynağını kullanır; farklı müşteri bağlamı reddedilir',async()=>{
 for(const [id,name] of [[1,'Vizyona'],[2,'Terzioğlu Shop'],[999,'Yeni Müşteri']]){
  const snapshot={companyId:id,sender:{name,hasLogo:true}},before=JSON.stringify(snapshot);
  const company=deliveryExportCompany(snapshot,{company:{id,name}});
  assert.equal(companyLogoSource(company),'/api/management/logo?fit=1&company='+id);
  assert.equal(company.name,name);assert.equal(JSON.stringify(snapshot),before);
  assert.throws(()=>deliveryExportCompany(snapshot,{company:{id:id+1,name:'Başka müşteri'}}),/Rapor firması/);
 }
 assert.throws(()=>deliveryExportCompany({companyId:0,sender:{hasLogo:true}}),/Rapor firması/);
 assert.equal(await companyExportLogo({id:3,name:'Logosuz müşteri',hasLogo:false}),null);
});

test('Vizyona, Terzioğlu ve yeni müşteride Excel şablonu aynıdır; marka ve logo müşteriye aittir',async()=>{
 const order={provider:'Hepsiburada',orderNumber:'0000123',recipientName:'Örnek alıcı',address:'Örnek adres',phone:'05000000000',city:'Ankara',createdAt:'2026-10-27T10:00:00+03:00',products:[{sku:'001',title:'Örnek ürün',quantity:2}]};
 const logoData=await fs.readFile(new URL('../public/assets/omnigo-favicon.png',import.meta.url));
 const logo={data:'data:image/png;base64,'+logoData.toString('base64'),width:64,height:64};
 let reference;
 for(const name of ['Vizyona','Terzioğlu Shop','Yeni Müşteri']){
  const snapshot={companyId:1,city:'Ankara',sender:{name,phone:'05000000001'},orders:[order]},before=JSON.stringify(snapshot);
  const workbook=new Workbook();await workbook.xlsx.load(await (await createDeliveryWorkbook(snapshot,{Hepsiburada:logo,sender:logo})).arrayBuffer());
  const sheet=workbook.getWorksheet(1);
  assert.equal(workbook.creator,name);assert.ok(sheet.getSheetValues().flat(2).includes(name));
  assert.equal(sheet.getImages().length,2);assert.equal(JSON.stringify(snapshot),before);
  const shape={columns:sheet.columns.map(c=>c.width),rows:Array.from({length:sheet.rowCount},(_,i)=>({height:sheet.getRow(i+1).height,cells:sheet.getRow(i+1).values.map((_,col)=>sheet.getCell(i+1,col||1).style)})),setup:sheet.pageSetup,views:sheet.views,images:sheet.getImages().map(i=>({col:i.range.tl.col,row:i.range.tl.row,ext:i.range.ext}))};
  if(reference)assert.deepEqual(shape,reference);else reference=shape;
 }
});

test('Teslimat çıktısı seçili mağaza adını gösterirken gönderici bilgilerini korur',async()=>{
 const snapshot={companyId:2,city:'Ankara',company:{id:2,name:'TERZİOĞLU SHOP'},sender:{name:'Örnek Ticari Unvan'},
  orders:[{provider:'Hepsiburada',orderNumber:'0000123',recipientName:'Örnek alıcı',address:'Örnek adres',city:'Ankara',products:[{sku:'001',title:'Örnek ürün',quantity:2}]}]};
 const workbook=new Workbook();await workbook.xlsx.load(await (await createDeliveryWorkbook(snapshot,{})).arrayBuffer());
 const sheet=workbook.getWorksheet(1);
 assert.equal(workbook.creator,'TERZİOĞLU SHOP');
 assert.equal(sheet.getCell('A2').value,'TERZİOĞLU SHOP');
 assert.ok(sheet.getSheetValues().flat(2).includes('Örnek Ticari Unvan'));
 assert.ok(sheet.getSheetValues().flat(2).includes('Teslimat Evrakı · Ankara'));
 for(const [w,h,expected] of [[200,200,[22,6,18,18]],[400,100,[10,9.75,42,10.5]],[100,400,[28.75,6,4.5,18]]]){
  const calls=[];
  drawCompanyPdfLogo({addImage:(...values)=>calls.push(values)},{data:'example',width:w,height:h},10,6,42,18);
  assert.deepEqual(calls,[['example','PNG',...expected]]);
 }
});

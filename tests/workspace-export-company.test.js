import assert from 'node:assert/strict';
import test from 'node:test';
import {Workbook} from '../public/vendor/report-xlsx-070.js';
import {workspaceExportCompany} from '../public/workspace-export-company.js';
import {fileCenterStockFilename} from '../public/file-center-export-name.js';
import {createFileCenterStockFile} from '../public/file-center-stock-export.js';
import {createInboxWorkbook,downloadInboxWorkbook} from '../public/inbox-question-download.js';

const created=new Date('2026-11-15T22:00:00Z');
const company={id:2,name:'Örnek Beyaz Eşya',hasLogo:true};
const stale={id:1,name:'VİZYONA',hasLogo:true};
const stock={page:1,providers:[],items:[{name:'Çamaşır Makinesi',sku:'A123'}]};
const question={companyId:2,provider:'Koçtaş',title:'Örnek ürün',question:'Ürün stokta mı?',status:'waiting'};

test('Çıktı markası mağaza kimliğiyle eşleşen çalışma alanından alınır',()=>{
 const state={data:{company:stale},companies:[stale,company]};
 assert.deepEqual(workspaceExportCompany(state,2),company);
 assert.equal(workspaceExportCompany(state,3),undefined);
 state.data.company={...company,name:'Yeni ad'};
 assert.equal(workspaceExportCompany(state,2).name,'Yeni ad');
});

test('Stok ve soru XLSX dosyaları mağazaya ait başlık, logo ve karakterleri korur',async()=>{
 const png='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+VrXIAAAAASUVORK5CYII=';
 const requested=[];
 const originalImage=globalThis.Image,originalDocument=globalThis.document;
 globalThis.Image=class {
  naturalWidth=1;
  naturalHeight=1;
  set src(value){requested.push(value);queueMicrotask(()=>this.onload());}
 };
 globalThis.document={createElement:type=>{
  assert.equal(type,'canvas');
  return {getContext:()=>({drawImage(){}}),toDataURL:()=>png};
 }};
 try{
  const generated=[await createFileCenterStockFile(stock,company,created),await createInboxWorkbook([question],company,created)];
  assert.equal(generated[0].filename,'Örnek Beyaz Eşya – 16.11.2026 Stok Listesi.xlsx');
  assert.equal(generated[0].filename,fileCenterStockFilename(stock,company,created));
  assert.equal(generated[1].filename,'Örnek Beyaz Eşya – 16.11.2026 Pazaryeri Soruları.xlsx');
  assert.equal(requested.length,2);
  for(const source of requested){
   const url=new URL(source,'https://example.test');
   assert.equal(url.pathname,'/api/management/logo');
   assert.equal(url.searchParams.get('company'),'2');
  }
  for(const [index,file] of generated.entries()){
   const workbook=new Workbook();
   await workbook.xlsx.load(new Uint8Array(await file.blob.arrayBuffer()));
   const sheet=workbook.worksheets[0];
   assert.equal(workbook.creator,company.name);
   assert.equal(sheet.getCell('B2').value,company.name);
   assert.ok(sheet.getCell('B3').value);
   assert.equal(sheet.getImages()[0].range.tl.nativeCol,0);
   assert.equal(sheet.getImages().length,1);
   assert.equal(sheet.views[0].ySplit,7);
   assert.equal(sheet.getCell('A7').value,index===0?'Ürün':'Pazaryeri');
   assert.equal(sheet.getCell('A8').value,index===0?'Çamaşır Makinesi':'Koçtaş');
  }
  const unbranded=await createInboxWorkbook([question],{...company,hasLogo:false},created);
  const workbook=new Workbook();await workbook.xlsx.load(new Uint8Array(await unbranded.blob.arrayBuffer()));
  assert.equal(workbook.worksheets[0].getCell('A2').value,company.name);
  assert.equal(workbook.worksheets[0].getImages().length,0);
 }finally{globalThis.Image=originalImage;globalThis.document=originalDocument;}
});

test('Kayıtlı logo okunamazsa stok ve soru Excel dosyaları eksik marka ile gönderilmez',async()=>{
 const original=globalThis.Image;
 globalThis.Image=class {set src(value){queueMicrotask(()=>this.onerror());}};
 try{
  await assert.rejects(createFileCenterStockFile(stock,company,created),/logosu yüklenemedi/);
  await assert.rejects(createInboxWorkbook([question],company,created),/logosu yüklenemedi/);
 }finally{globalThis.Image=original;}
});

test('Mağaza kimliği farklıysa soru çıktısı kaynak okumasından önce reddedilir',async()=>{
 const params=new URLSearchParams({company:'2',presentation:'download'});
 await assert.rejects(downloadInboxWorkbook(params,()=>true,stale),/Rapor firması belirlenemedi/);
});

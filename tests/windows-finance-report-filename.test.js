import assert from 'node:assert/strict';
import test from 'node:test';
import {invokeReportBridge} from '../public/report-file-action.js';
import {windowsShareFilename} from '../public/share-filename.js';

const note='TERZİOĞLU SHOP-rapor-2026-11.pdf';
const profit='VİZYONA 2026-11-01_2026-11-20 Koçtaş Kârlılık Analizi.pdf';
const rejected=()=>new Error('Rapor dosyasının adı geçersiz.');
function oldWindows(calls){
 return {__OMNIGO_DESKTOP__:true,async send(name,data){
  calls.push([name,data]);
  if(!name.startsWith('Omnigo-')||new TextEncoder().encode(name).length>240)throw rejected();
  return 'opened';
 }};
}

test('Windows ad reddinde rapor notu ve kârlılık PDF içerikleri korunur',async()=>{
 for(const name of [note,profit,windowsShareFilename(profit),profit.replace('Koçtaş ','')]){
  const calls=[];
  assert.equal(await invokeReportBridge('send',name,'pdf',()=>true,oldWindows(calls)),'opened');
  assert.deepEqual(calls,[[name,'pdf'],['Omnigo-'+name,'pdf']]);
 }
 const suffix=' 2026-11-01_2026-11-20 Hepsiburada Kârlılık Analizi.pdf',calls=[];
 await invokeReportBridge('send','商'.repeat(65)+suffix,'payload',()=>true,oldWindows(calls));
 assert(calls[1][0].endsWith(suffix));
 assert(new TextEncoder().encode(calls[1][0]).length<=240);
});

test('Kabul edilen ad aynen kalır, farklı hatalar ve yetkisi kaybolan göndermeler tekrarlanmaz',async()=>{
 for(const name of [note,profit,'Mağaza-rapor-2026-11.xlsx','Omnigo-Özet.pdf']){
  const calls=[],native={__OMNIGO_DESKTOP__:true,send:async name=>{calls.push(name);return 'saved';}};
  assert.equal(await invokeReportBridge('send',name,'data',()=>true,native),'saved');
  assert.deepEqual(calls,[name]);
 }
 for(const name of ['../'+note,'Belge.pdf','Kayıt-rapor-2026-19.pdf',profit+'.exe']){
  const calls=[];
  await assert.rejects(invokeReportBridge('send',name,'data',()=>true,oldWindows(calls)),/geçersiz/);
  assert.equal(calls.length,1);
 }
 const calls=[];
 assert.equal(await invokeReportBridge('send',note,'data',()=>false,oldWindows(calls)),'stale');
 assert.equal(calls.length,1);
 await assert.rejects(invokeReportBridge('send',profit,'data',()=>true,{...oldWindows([]),send:async()=>{throw new Error('Paylaşım açılamadı.');}}),/Paylaşım açılamadı/);
 await assert.rejects(invokeReportBridge('send',profit,'data',()=>true,{...oldWindows([]),__OMNIGO_DESKTOP__:false}),/geçersiz/);
});

test('Şehir Dışı Ürünler PDF adı güncel köprüde korunur, eski Windows reddederse uyumlu ad kullanılır',async()=>{
 for(const suffix of ['Şehir Dışı Ürünler','Sehir Disi Urunler','Out-of-City Products','Produkte für auswärtige Lieferungen','Produkte fur auswartige Lieferungen']){
  const name='VİZYONA Tüm İller 21.11.2026 '+suffix+'.pdf',calls=[];
  assert.equal(await invokeReportBridge('send',name,'pdf',()=>true,oldWindows(calls)),'opened');
  assert.deepEqual(calls,[[name,'pdf'],['Omnigo-'+name,'pdf']]);
  const modern=[];
  await invokeReportBridge('send',name,'pdf',()=>true,{__OMNIGO_DESKTOP__:true,send:async n=>{modern.push(n);return 'saved';}});
  assert.deepEqual(modern,[name]);
 }
});

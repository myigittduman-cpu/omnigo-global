import test from 'node:test';
import assert from 'node:assert/strict';
import {saveReportFile,downloadStatus,prepareReportSave} from '../public/report-download.js';

const blob=new Blob(['%PDF-1.7\nTürkçe rapor']);
test('Excel picker uses xlsx MIME and extension',async()=>{
 let options;await prepareReportSave('Omnigo-Eskişehir.xlsx',{showSaveFilePicker:async value=>{options=value;return {};}});
 assert.deepEqual(options.types[0].accept,{'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':['.xlsx']});
});
test('Belge görselini kaydederken JPEG uzantısı PDF olarak değiştirilmez',async()=>{
 let options;await prepareReportSave('Omnigo-Fatura-42.jpeg',{showSaveFilePicker:async value=>{options=value;return {};}});
 assert.deepEqual(options.types[0].accept,{'image/jpeg':['.jpeg','.jpg']});
});
test('delivery picker suggests Desktop and the exact Turkish city filename',async()=>{
 const handle={};let options;
 assert.equal(await prepareReportSave('Eskişehir Teslimat Evrakları.pdf',{showSaveFilePicker:async value=>{options=value;return handle;}}),handle);
 assert.equal(options.startIn,'desktop');assert.equal(options.suggestedName,'Eskişehir Teslimat Evrakları.pdf');
 assert.equal(await prepareReportSave('Kırşehir Teslimat Evrakları.pdf',{showSaveFilePicker:async()=>{throw {name:'AbortError'};}}),'cancelled');
 await assert.rejects(prepareReportSave('Ankara Teslimat Evrakları.pdf',{__OMNIGO_DESKTOP__:true,__OMNIGO_DESKTOP_VERSION__:'0.2.23'}),/0.2.24/);
 assert.equal(await prepareReportSave('Ankara Teslimat Evrakları.pdf',{__OMNIGO_DESKTOP__:true,__OMNIGO_DESKTOP_VERSION__:'0.2.24'}),null);
});
test('browser picker writes exact PDF bytes and aborts after a workspace switch',async()=>{
 let saved,closed=0,aborted=0,current=true;
 const stream={write:async value=>{saved=await value.text();},close:async()=>{closed++;},abort:async()=>{aborted++;}};
 const handle={createWritable:async()=>stream};
 assert.equal(await saveReportFile(blob,'Eskişehir Teslimat Evrakları.pdf',()=>current,{},handle),'saved');
 assert.equal(saved,await blob.text());assert.equal(closed,1);
 stream.write=async()=>{current=false;};
 assert.equal(await saveReportFile(blob,'Eskişehir Teslimat Evrakları.pdf',()=>current,{},handle),'stale');
 assert.equal(closed,1);assert.equal(aborted,1);
});
class Reader {readAsDataURL(value){value.arrayBuffer().then(data=>{this.result='data:application/pdf;base64,'+Buffer.from(data).toString('base64');this.onload();});}}
test('native report save transmits exact bytes and handles cancel/failure without a false success',async()=>{
 const environment={FileReader:Reader,__OMNIGO_SAVE_REPORT__:async(name,data)=>{assert.equal(name,'Omnigo-test.pdf');assert.equal(Buffer.from(data,'base64').toString(),await blob.text());return 'saved';}};
 assert.equal(await saveReportFile(blob,'Omnigo-test.pdf',()=>true,environment),'saved');
 environment.__OMNIGO_SAVE_REPORT__=async()=> 'cancelled';
 assert.equal(await saveReportFile(blob,'Omnigo-test.pdf',()=>true,environment),'cancelled');
 assert.match(downloadStatus('cancelled'),/iptal/);
 environment.__OMNIGO_SAVE_REPORT__=async()=>{throw new Error('Dosya açık.');};
 await assert.rejects(saveReportFile(blob,'Omnigo-test.pdf',()=>true,environment),/Dosya açık/);
 environment.__OMNIGO_SAVE_REPORT__=async()=> 'unknown';
 await assert.rejects(saveReportFile(blob,'Omnigo-test.pdf',()=>true,environment),/doğrulanamadı/);
});
test('Tarayıcı kayıt yeri sorar; iptalde veya destek yoksa otomatik indirme yapmaz',async()=>{
 let options,writes=0;const environment={File,showSaveFilePicker:async value=>{options=value;return {createWritable:async()=>({write:async()=>{writes++;},close:async()=>{},abort:async()=>{}})};}};
 assert.equal(await saveReportFile(blob,'Omnigo-test.pdf',()=>true,environment),'saved');assert.equal(writes,1);assert.equal(options.suggestedName,'Omnigo-test.pdf');
 environment.showSaveFilePicker=async()=>{throw {name:'AbortError'};};
 assert.equal(await saveReportFile(blob,'Omnigo-test.pdf',()=>true,environment),'cancelled');assert.equal(writes,1);
 await assert.rejects(saveReportFile(blob,'Omnigo-test.pdf',()=>true,{File,navigator:{}}),/kayıt konumu seçimini desteklemiyor/);
 await assert.rejects(saveReportFile(blob,'Omnigo-test.pdf',()=>true,{__OMNIGO_DESKTOP__:true}),/giriş ekranındaki “Güncellemeler”/);
});
test('iOS tarayıcıda dosyalara kaydet menüsüne yalnız dosya eklenir',async()=>{
 let data;const environment={File,navigator:{canShare:()=>true,share:async value=>{data=value;}}};
 assert.equal(await saveReportFile(blob,'Kırşehir.pdf',()=>true,environment),'save-sheet');assert.deepEqual(Object.keys(data),['files']);assert.equal(data.files[0].name,'Kırşehir.pdf');
});
test('workspace changes and oversized reports never reach the save dialog',async()=>{
 let current=true,calls=0;
 class DelayedReader extends Reader{readAsDataURL(blob){current=false;super.readAsDataURL(blob);}}
 const environment={FileReader:DelayedReader,__OMNIGO_SAVE_REPORT__:()=>{calls++;}};
 assert.equal(await saveReportFile(blob,'Omnigo-test.pdf',()=>current,environment),'stale');assert.equal(calls,0);
 await assert.rejects(saveReportFile({size:33*1024*1024},'Omnigo-test.pdf',()=>true,environment),/çok büyük/);
});

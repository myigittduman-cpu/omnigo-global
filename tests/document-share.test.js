import test from 'node:test';
import assert from 'node:assert/strict';
import {bindDocumentShare,documentShareControls} from '../public/document-share.js';
import {shareReportFile} from '../public/report-file-action.js';
import {shareFilename,windowsShareFilename} from '../public/share-filename.js';
function fixture(){
 const pdf={dataset:{prepareShare:'pdf'}},excel={dataset:{prepareShare:'xlsx'}},message={},shared=[];
 const environment={File,navigator:{canShare:data=>data.files.length===1,share:async data=>{shared.push(data);}}};
 return {pdf,excel,message,shared,environment,container:{querySelector:()=>message,querySelectorAll:()=>[pdf,excel]}};
}
const createFile=async type=>({filename:'Kırıkkale 21.10.2026 Teslimat Evrakları.'+type,blob:new Blob(['örnek '+type])});
test('Windows WhatsApp adı uyumludur; normal indirme adı Türkçe kalır',async()=>{
 class Reader{readAsDataURL(blob){blob.arrayBuffer().then(value=>{this.result='data:application/octet-stream;base64,'+Buffer.from(value).toString('base64');this.onload();});}}
 const calls=[],environment={FileReader:Reader,__OMNIGO_DESKTOP__:true,__OMNIGO_SHARE_REPORT__:async(...args)=>{calls.push(args);return 'opened';}};
 for(const kind of ['Teslimat Evrakları','Şehir Dışı Sevkiyat Listesi'])for(const ext of ['pdf','xlsx']){
  const filename=`Tüm Şehirler 21.10.2026 ${kind}.${ext}`,file=new File(['Türkçe içerik'],filename);
  await shareReportFile(file,()=>true,environment);
  assert.equal(calls.at(-1)[0],windowsShareFilename(filename));assert.match(calls.at(-1)[0],/^[\x20-\x7E]+$/);
  assert.equal(Buffer.from(calls.at(-1)[1],'base64').toString(),'Türkçe içerik');assert.equal(file.name,filename);assert.equal(shareFilename(filename),filename);
 }
 assert.equal(windowsShareFilename('Tüm Şehirler 21.10.2026 Şehir Dışı Sevkiyat Listesi.pdf'),'Tum Sehirler 21.10.2026 Sehir Disi Sevkiyat Listesi.pdf');
 assert.equal(windowsShareFilename('ÇĞİÖŞÜ çğıöşü.xlsx'),'CGIOSU cgiosu.xlsx');
});
test('WhatsApp iki biçim sunar; indirme ve mesaj seçenekleri içermez',()=>{
 const html=documentShareControls();assert.equal((html.match(/<button /g)||[]).length,2);
 assert.doesNotMatch(html,/indir|data-copy-share-caption|data-download-share/);
});
test('Windows dosya paylaşımı uyumlu adı ve PDF içeriğini iletir, indirme adını korur',async()=>{
 const file=new File(['%PDF-örnek'],'İş Ortağım.pdf',{type:'application/pdf'});
 let shared;
 const env={File,navigator:{userAgent:'Windows NT',canShare:()=>true,share:async({files})=>{[shared]=files;}}};
 assert.equal(await shareReportFile(file,()=>true,env),'opened');
 assert.equal(shared.name,'Is Ortagim.pdf');assert.equal(file.name,'İş Ortağım.pdf');
 assert.equal(shared.type,'application/pdf');assert.equal(await shared.text(),await file.text());
});
test('Hazır dosya biçim tıklamasında beklemeden, doğru MIME ve Türkçe adla paylaşılır',async()=>{
 const f=fixture(),binding=bindDocumentShare(f.container,{current:()=>true,createFile},f.environment);
 await binding.ready;assert.equal(f.shared.length,0);
 const pending=f.excel.onclick();assert.equal(f.shared.length,1);await pending;await f.pdf.onclick();
 assert.equal(f.excel.textContent,'Excel dökümünü hazırla');assert.equal(f.pdf.textContent,'PDF oluştur');
 assert.deepEqual(f.shared.map(x=>x.files[0].name),['Kırıkkale 21.10.2026 Teslimat Evrakları.xlsx','Kırıkkale 21.10.2026 Teslimat Evrakları.pdf']);
 assert.equal(f.shared[0].files[0].type,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
 assert.equal(await f.shared[0].files[0].text(),'örnek xlsx');assert.deepEqual(Object.keys(f.shared[0]),['files']);
});
test('Sayfa değişince veya destek yoksa dosya indirilmez ve paylaşılmış sayılmaz',async()=>{
 const f=fixture();let current=true;const binding=bindDocumentShare(f.container,{current:()=>current,createFile},f.environment);await binding.ready;
 current=false;await f.excel.onclick();assert.equal(f.shared.length,0);
 await assert.rejects(shareReportFile(new File(['x'],'örnek.xlsx'),()=>true,{navigator:{}}),/desteklemiyor/);
});
test('Çift tıklama ikinci paylaşımı açmaz; kullanıcı iptalinde indirme yapılmaz',async()=>{
 const f=fixture();let finish;f.environment.navigator.share=()=>new Promise(resolve=>finish=resolve);
 const binding=bindDocumentShare(f.container,{current:()=>true,createFile},f.environment);await binding.ready;
 const pending=f.excel.onclick();assert.equal(f.pdf.disabled,true);await f.pdf.onclick();finish();await pending;
 f.environment.navigator.share=async()=>{throw {name:'AbortError'};};await f.pdf.onclick();assert.equal(f.message.textContent,'Paylaşım iptal edildi.');
});
test('Üretim hatasında o biçim paylaşılmaz; yeniden deneme mümkündür',async()=>{
 const f=fixture();let fail=true;const binding=bindDocumentShare(f.container,{current:()=>true,createFile:async type=>{if(type==='xlsx'&&fail)throw new Error('Liste alınamadı');return createFile(type);}},f.environment);await binding.ready;
 assert.equal(f.message.textContent,'Liste alınamadı');fail=false;await f.excel.onclick();assert.equal(f.shared.length,0);await f.excel.onclick();assert.equal(f.shared.length,1);
});
test('Bir paylaşım tamamlandığında halen hazırlanan diğer biçim açılmaz',async()=>{
 const f=fixture();let finishPdf;
 const binding=bindDocumentShare(f.container,{current:()=>true,createFile:type=>type==='pdf'?new Promise(resolve=>finishPdf=resolve):createFile(type)},f.environment);
 await new Promise(resolve=>setImmediate(resolve));await f.excel.onclick();assert.equal(f.pdf.disabled,true);
 finishPdf(await createFile('pdf'));await binding.ready;assert.equal(f.pdf.disabled,false);
});
test('Uygulama paylaşım bağlantısına doğru dosya ve içerik gönderilir',async()=>{
 class Reader{readAsDataURL(blob){blob.arrayBuffer().then(value=>{this.result='data:application/pdf;base64,'+Buffer.from(value).toString('base64');this.onload();});}}
 const file=new File(['%PDF-1.7 Türkçe'],'Kırşehir 21.10.2026 Teslimat Evrakları.pdf',{type:'application/pdf'});
 let calls=0;const environment={FileReader:Reader,__OMNIGO_SHARE_REPORT__:async(name,contents)=>{calls++;assert.equal(name,file.name);assert.equal(Buffer.from(contents,'base64').toString(),await file.text());return 'opened';}};
 assert.equal(await shareReportFile(file,()=>true,environment),'opened');assert.equal(calls,1);
 environment.__OMNIGO_SHARE_REPORT__=async()=> 'saved';await assert.rejects(shareReportFile(file,()=>true,environment),/Paylaşım ekranı açılamadı/);
});

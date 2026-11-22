import test from 'node:test';
import assert from 'node:assert/strict';
import {latestPageRead} from '../public/latest-page-read.js';
test('Yeni filtre eski isteği iptal eder; eski tamamlanma yeni yükleme durumunu bozamaz',()=>{
 const model={},reads=latestPageRead(model,()=>true),first=reads.begin(),second=reads.begin();
 assert.equal(first.signal.aborted,true);assert.equal(first.active(),false);assert.equal(first.finish(),false);
 assert.equal(model.loading,true);assert.equal(second.active(),true);assert.equal(second.finish(),true);assert.equal(model.loading,false);
});
test('Müşteri veya sayfa değişiminden sonraki yanıt ekrana uygulanamaz',()=>{
 let current=true;const reads=latestPageRead({},()=>current),request=reads.begin();current=false;
 assert.equal(request.active(),false);assert.equal(request.finish(),false);
});
test('Kayıt işlemi okumayı geçersiz kılar; okuma ayrı işlem kilidini değiştirmez',()=>{
 const model={busy:true},reads=latestPageRead(model,()=>true),request=reads.begin();reads.cancel();
 assert.equal(request.signal.aborted,true);assert.equal(request.active(),false);assert.equal(model.loading,false);assert.equal(model.busy,true);
});

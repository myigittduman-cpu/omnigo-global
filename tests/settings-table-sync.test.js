import test from 'node:test';
import assert from 'node:assert/strict';
import {tableSyncChoice,tableSyncList,tableSyncPanel,tableSyncRun} from '../public/settings-table-sync-view.js';
import {pazaramaHistoryState} from '../public/pazarama-history-form.js';
const stock={key:'inventory',label:'Stok <Yönetimi>',modes:['incremental','full'],providers:['n11'],limits:['Sınır <denetimi>']};
const archive={from:'2026-06-01',untilExclusive:'2026-09-01',total:33,present:0,missing:33,job:null};
const historyStatus=history=>pazaramaHistoryState({configured:true,version:3,sync:{startDate:'2026-09-01'},history});
test('Yalnız seçili mağazaya ait geçerli bir arşiv varsa geçmiş sipariş işlemi sunulur',()=>{
 assert.equal(historyStatus(null),null);
 assert.equal(historyStatus({...archive,from:'2026-02-30'}),null);
 assert.equal(historyStatus({...archive,missing:32}),null);
 const state=historyStatus(archive);
 assert.equal(state.from,'2026-06-01');assert.equal(state.max,'2026-08-31');assert.equal(state.canRequest,true);
 assert.match(state.message,/01\.06\.2026.*31\.08\.2026/);assert.match(state.message,/Eksik sipariş sayısı 33/);
});
test('Arşiv sayımı canlı kapsamdan bağımsızdır, etkin istek yinelenmez ve eksik sonuç yeniden denenebilir',()=>{
 for(const phase of ['queued','running']){
  assert.equal(historyStatus({...archive,job:{state:phase}}).canRequest,false);
 }
 for(const phase of ['incomplete','failed']){
  assert.equal(historyStatus({...archive,job:{state:phase}}).canRequest,true);
 }
 const done=historyStatus({...archive,present:33,missing:0,job:{state:'verified',from:'2026-06-01'}});
 assert.equal(done.canRequest,false);assert.match(done.message,/aktarımı doğrulandı/);assert.match(done.message,/Eksik sipariş sayısı 0/);
});
test('Mağaza ve tablo adları yalnız metin olarak gösterilir, boş kaynak başlatılamaz',()=>{
 assert.match(tableSyncPanel({name:'A&B'}),/A&amp;B/);
 assert.match(tableSyncList([stock]),/Stok &lt;Yönetimi&gt;/);
 assert.match(tableSyncList([{...stock,modes:[]}]),/data-sync-target="inventory"[^>]*disabled/);
 assert.match(tableSyncList([]),/kurulmuş canlı aktarım tablosu bulunmuyor/);
});
test('Sunucunun desteklediği modlar ve veri sınırları seçilebilir',()=>{
 const view=tableSyncChoice(stock);
 assert.match(view,/value="incremental" required checked/);
 assert.match(view,/value="full"/);
 assert.match(view,/Sınır &lt;denetimi&gt;/);
 assert.doesNotMatch(tableSyncChoice({...stock,modes:['incremental']}),/value="full"/);
});
test('Başlatma, çalışma, tamamlama ve hatalar kendi sonuçlarıyla gösterilir',()=>{
 for(const [state,label] of Object.entries({queued:'Sırada',running:'Aktarılıyor',complete:'Tamamlandı',failed:'Tamamlanamadı'})){
  const markup=tableSyncRun({table:'inventory',mode:'incremental',state,sources:[{state:'failed',key:'catalog:n11',added:3,error:'Hata <1>'}]},[stock]);
  assert.ok(markup.includes(label));assert.match(markup,/n11.*Katalog: Hata &lt;1&gt;/);
  assert.equal(markup.includes('kayıt: 3'),state==='complete');
 }
 assert.match(tableSyncRun(null,[]),/Henüz aktarım istenmedi/);
});

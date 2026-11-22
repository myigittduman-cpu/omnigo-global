import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {healthResult} from '../public/connection-health.js';

test('sağlıklı aktarım kartları geçici çalışma metni yerine son kesin sonucu kullanır',()=>{
 const php=fs.readFileSync(new URL('../php/src/ConnectionHealth.php',import.meta.url),'utf8');
 assert.match(php,/default=>'Son sipariş aktarımı başarılı\.'/);
 assert.match(php,/Satış özeti son doğrulanmış siparişlerden hesaplandı\./);
 assert.doesNotMatch(php,/status==='ready'\)\$message='Sipariş aktarımı çalışıyor/);
});

const orderCell=item=>healthResult({company:{id:1,name:'Örnek mağaza'},checkedAt:'2026-11-22T00:00:00Z',items:[{companyId:1,channel:'n11',storeName:'Örnek',active:true,status:'ready',...item}]}).match(/<tbody>[\s\S]*?<td>([\s\S]*?)<\/td>/)?.[1];

test('bekleyen mutabakat son başarılı sipariş sonucunu gizlemez, mutabakatı tamamlandı saymaz',()=>{
 const cell=orderCell({reconciliation:{state:'waiting'}});
 assert.match(cell,/class="health-result is-healthy">✓/);
 assert.match(cell,/Mutabakat bekleniyor/);
 assert.doesNotMatch(cell,/Mutabakat sağlandı/);
});

test('gerçek mutabakat farkı ve aktarım hatası başarı işaretine dönüşmez',()=>{
 for(const item of [{reconciliation:{state:'issue'}},{status:'error'},{status:'missing_queue'}]){
  const cell=orderCell(item);
  assert.match(cell,/class="health-result needs-check">✕/);
  assert.doesNotMatch(cell,/is-healthy/);
 }
 for(const item of [{active:false},{status:'retry'},{status:'waiting'}])assert.doesNotMatch(orderCell(item),/is-healthy/);
});

test('mutabakat beklemesi sağlıklı sonucu bozmaz, gerçek fark hata kalır',()=>{
 const source=fs.readFileSync(new URL('../public/connection-health.js',import.meta.url),'utf8');
 assert.match(source,/item\.reconciliation\?\.state !== 'issue'/);
 assert.match(source,/item\.reconciliation\?\.state === 'issue'/);
});

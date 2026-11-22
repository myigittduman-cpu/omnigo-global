import test from 'node:test';import assert from 'node:assert/strict';
import {inventoryView} from '../public/inventory-view.js';
test('Ürün filtresi aramanın yanındadır; ortak select tasarımı ve seçim korunur',()=>{
 const html=inventoryView({admin:true,query:'Profilo',filter:'stocked',provider:'n11',category:'washing_machines',data:{items:[],settings:{mode:'both'},categories:[{value:'washing_machines',label:'Çamaşır Makinaları'},{value:'dishwashers',label:'Bulaşık Makinaları'}]}});
 assert.ok(html.indexOf('name="query"')<html.indexOf('name="category"')&&html.indexOf('name="category"')<html.indexOf('name="provider"'));
 assert.match(html,/class="inventory-filter inventory-category-filter"/);assert.match(html,/value="washing_machines" selected/);assert.match(html,/Bulaşık Makinaları/);
 assert.match(html,/Seçtiğiniz filtrelere uygun ürün bulunmuyor/);assert.match(html,/autocomplete="off"/);
});
test('Müşteri de aynı filtreyi görür; seçenekler HTML olarak çalışmaz',()=>{
 const html=inventoryView({admin:false,query:'',filter:'stocked',category:'',data:{items:[],categories:[{value:'other',label:'<script>test</script>'}]}});
 assert.match(html,/name="category"/);assert.match(html,/&lt;script&gt;/);assert.doesNotMatch(html,/<script>|data-stock-bulk/);
});

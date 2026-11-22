import test from 'node:test';
import assert from 'node:assert/strict';
import {correctionNote,correctionForm,missingValue} from '../public/delivery-correction.js';
test('Eksik alan ve kapsam dışı il ayrı, kırmızı ve güvenli gösterilir',()=>{
 assert.match(missingValue('','Telefon'),/delivery-missing/);
 const note=correctionNote({id:1,city:'Diğer / Kontrol',active:true,issues:['Teslimat ili kontrol edilmeli'],details:{province:'İstanbul'}});
 assert.match(note,/Kapsam dışı sipariş/);assert.match(note,/İstanbul/);assert.match(note,/data-correct-order/);assert.doesNotMatch(note,/ili eksik/);
 const unsafe=correctionNote({id:1,city:'Diğer / Kontrol',active:false,issues:['<script>'],details:{province:'<img>'}});assert.doesNotMatch(unsafe,/<script>|<img>|data-correct-order/);
});
test('Form eksik alanı işaretler, kaynak veriyi kaçırır ve kaydetme eylemi açık olur',()=>{
 const html=correctionForm({fields:[{key:'phone',label:'Telefon',value:'',error:true,message:'Telefon eksik',limit:60},{key:'address',label:'Adres',value:'</textarea><script>',error:false,message:'',limit:8000}]});
 assert.match(html,/has-error/);assert.match(html,/Kaydet ve otomatik yerleştir/);assert.doesNotMatch(html,/<script>/);assert.match(html,/Kapsam dışındaki il doğruysa değiştirmeyin/);
});

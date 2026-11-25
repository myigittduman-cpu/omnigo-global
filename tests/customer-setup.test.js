import test from 'node:test';
import assert from 'node:assert/strict';
import {setupProviders,supportTemplate} from '../public/customer-api-help.js';
import {setupView,connectionForm,reviewView} from '../public/customer-setup-view.js';
import {marketplaceSettingsView} from '../public/customer-marketplaces.js';
import {mutationPrompt} from '../public/action-confirmation.js';
import {createTransferPlan} from '../public/customer-transfer-plan.js';
test('Kurulum ve destek metinleri yalnız gerekli alanları içerir, şifreleri dışarı çıkarmaz',()=>{
 assert.equal(setupProviders.length,7);
 for(const provider of setupProviders){const template=supportTemplate(provider,'Örnek','Mağaza');assert.ok(template.includes(provider.name));assert.ok(template.includes('Stok ve fiyat'));assert.ok(template.includes('yaklaşan hakedişleri'));const html=connectionForm(provider,{storeName:'<img>',version:1},{apiKey:'PRIVATE-KEY',apiSecret:'PRIVATE-SECRET'}, {name:'Örnek'});assert.doesNotMatch(html,/PRIVATE-|<img>/);assert.match(html,/data-template="permissions"/);}
});
test('Kurulum adımları geniş ayrı bölümler, erteleme ve gerçek durum özeti sunar',()=>{
 const html=setupView({name:'<Firma>'},{profile:{},users:[]},[{name:'Trendyol',provider:'trendyol',selected:false}]);
 assert.equal((html.match(/data-step="/g)||[]).length,6);assert.match(html,/Daha sonra ekle/);assert.doesNotMatch(html,/<Firma>/);
 assert.match(html,/data-setup-start>Canlı Aktarımı Başlat/);
 const report=reviewView([{name:'n11',selected:true,configured:false}],{items:[]},[]);assert.match(report,/API bilgileri daha sonra eklenecek/);assert.doesNotMatch(report,/bağlantı hazır/i);
});

test('Mevcut pazaryeri bağlantısı yalnız API ve son kontrol adımlarını sunar',()=>{
 const html=setupView({name:'Örnek'},{profile:{},users:[]},[],{provider:'n11'});
 const steps=[...html.matchAll(/data-step-label="(\d+)"/g)].map(match=>Number(match[1]));
 assert.deepEqual(steps,[4,5]);assert.match(html,/ilk kurulum ve eksik adımları/);
 assert.doesNotMatch(html,/data-setup-start/);
});
test('Müşteri kurulumu kullanıcı hesabı adımına doğrudan dönebilir',()=>{
 const html=setupView({name:'Örnek'},{profile:{},users:[]},[],{initialStep:2,canManageAccounts:true});
 const visible=[...html.matchAll(/<section data-step="(\d+)"\s*([^>]*)>/g)].filter(match=>!match[2].includes('hidden'));
 assert.deepEqual(visible.map(match=>Number(match[1])),[2]);
 assert.match(html,/data-setup-manage-accounts>Kullanıcı hesaplarını ve yetkilerini yönet/);
});
test('API hazırlıkları yalnız başlatıldığında gönderilir; başarısız bağlantı yeniden denenebilir',async()=>{
 const sent=[];
 const plan=createTransferPlan({companyId:2,start:async input=>{
  sent.push(input);
  return {companyId:2,markets:{hepsiburada:{state:'scheduled'},n11:{state:'error'}}};
 }});
 plan.prepare('hepsiburada',{version:0,marketVersion:7,serviceKey:'sample'});
 plan.prepare('n11',{version:0,marketVersion:6,apiKey:'sample2'});
 assert.equal(sent.length,0);
 await plan.send(3);
 assert.deepEqual(Object.keys(sent[0].connections),['hepsiburada','n11']);
 assert.equal(sent[0].profileVersion,3);
 await plan.send(3);
 assert.deepEqual(Object.keys(sent[1].connections),['n11']);
 plan.clear();
 assert.equal(plan.count,0);
});
test('Pazaryeri işlem onayları ayrımı açıklarken otomatik veya salt okunur çağrıları durdurmaz',()=>{
 assert.match(mutationPrompt('/management/marketplaces/n11?company=4','POST',{action:'delete'}),/geçmiş siparişler ve raporlar korunur/);
 assert.match(mutationPrompt('/shipments/5/delete?company=1','POST'),/emin misiniz/);
 assert.equal(mutationPrompt('/shipments/search','POST'),null);assert.equal(mutationPrompt('/shipments/editing','POST',{action:'renew'}),null);assert.equal(mutationPrompt('/push/register','POST'),null);assert.equal(mutationPrompt('/login','POST'),null);
 const html=marketplaceSettingsView([{provider:'n11',name:'n11',selected:true,storeName:'<script>',configured:true}]);assert.match(html,/data-marketplace-configure/);assert.match(html,/Mağaza ayarları/);assert.doesNotMatch(html,/<script>/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {customerPageNames,customerModuleEnabled,customerModuleChoices} from '../public/customer-modules.js';
import {portalNavigation} from '../public/portal-navigation.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';
test('Yeni müşteri bölümleri bağımsızdır; diğer menüler ve mevcut müşteriler korunur',()=>{
 const names={channels:'Pazaryerlerim',orders:'Siparişler',shipments:'Şehir Dışı Sevkiyat',deliveries:'Teslimat Evrakları'};
 assert.deepEqual(customerPageNames(names,{user:{role:'admin'}}),{...names,channels:'Genel bakış'});
 for(const shipments of [true,false])for(const deliveries of [true,false]){
  const state={user:{id:1,role:'admin'},data:{modules:{shipments,deliveries}}};
  assert.equal(customerModuleEnabled(state,'shipments'),shipments);assert.equal(customerModuleEnabled(state,'deliveries'),deliveries);
  assert.equal(customerModuleEnabled(state,'orders'),true);
  for(const renderer of [portalNavigation,workspaceNavigation]){const html=renderer(names,state);assert.equal(html.includes('data-nav="shipments"'),shipments);assert.equal(html.includes('data-nav="deliveries"'),deliveries);assert.ok(html.includes('data-nav="orders"'));}
  const client=workspaceNavigation(names,{...state,user:{id:2,role:'client'}});assert.equal(client.includes('data-nav="shipments"'),shipments);assert.equal(client.includes('data-nav="deliveries"'),deliveries);
 }
 assert.match(customerModuleChoices(),/name="shipmentsEnabled"/);assert.match(customerModuleChoices(),/name="deliveriesEnabled"/);
});

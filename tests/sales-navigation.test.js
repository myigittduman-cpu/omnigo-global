import test from 'node:test';
import assert from 'node:assert/strict';
import {customerPageNames} from '../public/customer-modules.js';
import {salesHomePage} from '../public/sales-navigation.js';
import {portalNavigation} from '../public/portal-navigation.js';

test('Mobil müşteri menüsü Siparişler kısayolunu yalnız görüntüleme izniyle gösterir',()=>{
 for(const allowed of [true,false]){
  const state={user:{role:'client'},view:'overview',data:{workspaceAccess:{pages:{overview:'shown',orders:'shown',analytics:'shown',payments:'shown'},grants:{overview:{view:true},orders:{view:allowed},analytics:{view:true},payments:{view:true}}}}};
  const html=portalNavigation({overview:'Genel bakış',orders:'Siparişler',analytics:'Analiz ve raporlar',payments:'Hakedişlerim'},state,{mobile:true});
  assert.equal(html.includes('data-nav="orders"'),allowed);
  assert.equal(html.includes('data-nav="analytics"'),!allowed);
 }
});

const names={overview:'Genel bakış',channels:'Pazaryerlerim',orders:'Siparişler'};
test('Tek satış menüsü gösterilir, müşterinin mevcut yetkileri genişletilmez',()=>{
 for(const overview of [true,false])for(const channels of [true,false]){
  const state={user:{role:'client'},data:{workspaceAccess:{pages:{overview:'shown',channels:'shown',orders:'shown'},grants:{overview:{view:overview},channels:{view:channels},orders:{view:true}}}}};
  const before=JSON.stringify(state),actual=customerPageNames(names,state);
  assert.deepEqual(actual,{...(overview?{overview:'Genel bakış'}:channels?{channels:'Genel bakış'}:{}),orders:'Siparişler'});
  assert.equal(salesHomePage(state),overview?'overview':'channels');
  assert.equal(JSON.stringify(state),before);
 }
 assert.deepEqual(customerPageNames(names,{user:{role:'admin'}}),{overview:'Genel bakış',orders:'Siparişler'});
});

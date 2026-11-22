import test from 'node:test';
import assert from 'node:assert/strict';
import {customerCan,customerPageVisible} from '../public/customer-access.js';
import {customerSensitiveVisibility} from '../public/customer-sensitive-visibility.js';
import {permissionMatrix,permissionSections} from '../public/customer-permissions-view.js';
import {sectionAllowsRole,sectionHasStoreRole} from '../public/customer-section-audience.js';

const access={pages:{visibility:'shown',returns:'shown',shipments:'shown'},grants:{visibility:{view:true,viewCancellations:true,viewReturns:true},returns:{view:true},shipments:{view:true,status:true}}};
test('Eski açık izinler müşteriye iptal/iade alanlarını tekrar açmaz; sevkiyat izni korunur',()=>{
 const state={user:{role:'client'},data:{workspaceAccess:structuredClone(access)}};
 assert.deepEqual(customerSensitiveVisibility(state),{cancellations:false,returns:false,afterCancellationTurnover:false});
 assert.equal(customerPageVisible(state,'returns'),false);
 assert.equal(customerCan(state,'returns'),false);
 assert.equal(customerPageVisible(state,'shipments'),true);
 assert.equal(customerCan(state,'shipments','status'),true);
 assert.deepEqual(customerSensitiveVisibility({...state,user:{role:'admin'}}),{cancellations:true,returns:true,afterCancellationTurnover:true});
 assert.equal(customerPageVisible({...state,user:{role:'admin'}},'returns'),true);
 assert.deepEqual(state.data.workspaceAccess,access);
});

test('Ayarlar iptal/iade alanları için müşteriye açılabilir bir kontrol sunmaz',()=>{
 const catalog={visibility:{label:'İptal / İade görünürlüğü',settingsOnly:true,adminOnly:true,actions:['view','viewCancellations','viewReturns']},returns:{label:'İade talepleri',adminOnly:true,actions:['view']},orders:{label:'Siparişler',actions:['view']}};
 const config={users:{},pages:{visibility:'shown',returns:'shown',orders:'shown'},defaults:{visibility:access.grants.visibility,returns:{view:true},orders:{view:true}}};
 const matrix=permissionMatrix(catalog,{view:'Görüntüleme'},config,'');
 assert.doesNotMatch(matrix,/data-page="(?:visibility|returns)"/);
 assert.doesNotMatch(matrix,/İptal \/ İade görünürlüğü|İade talepleri/);
 assert.match(matrix,/data-page="orders"/);
 const sections=permissionSections(catalog,config);
 assert.doesNotMatch(sections,/data-section="(?:visibility|returns)"/);
 assert.match(sections,/data-section="orders"/);
});

test('Bölüm seçimleri üç rolü ayırır ve eski görünürlük değerlerini doğru gösterir',()=>{
 const catalog={orders:{label:'Siparişler',actions:['view']},payments:{label:'Hakedişler',actions:['view']}};
 const defaults={orders:{view:true},payments:{view:false}};
 const config={pages:{orders:'shown',payments:'shown'},defaults,users:{7:defaults},roles:{7:'accounting_logistics'},roleDefaults:{accounting_logistics:defaults}};
 const options=page=>permissionSections({[page]:catalog[page]},config);
 assert.equal((options('payments').match(/<option /g)||[]).length,8);
 assert.doesNotMatch(options('payments'),/>Müşteri(?:\s*\/[^<]*)?<\/option>/);
 assert.match(options('payments'),/value="admin_manager" selected/);
 assert.match(options('orders'),/value="all_roles" selected/);
 assert.match(permissionMatrix(catalog,{view:'Görüntüleme'},config,'7'),/fieldset class="permission-card" disabled><legend>Hakedişler/);
 config.pages.payments='accounting_logistics';
 assert.match(options('payments'),/value="accounting_logistics" selected>[^<]*Muhasebe/);
 assert.match(permissionMatrix(catalog,{view:'Görüntüleme'},config,'7'),/fieldset class="permission-card" ><legend>Hakedişler/);
 config.pages.payments='removed';
 assert.match(options('payments'),/value="removed" selected/);
});

test('Destek ayarları Muhasebe ve Lojistik rolünü seçeneklerden çıkarır ve eski ayarları değiştirmez',()=>{
 const catalog={support:{label:'Destek talepleri',actions:['view','create','reply']}};
 const defaults={support:{view:true,create:true,reply:true}};
 const config={pages:{support:'all_roles'},defaults,users:{7:defaults,8:defaults},roles:{7:'accounting_logistics',8:'store_manager'}};
 const actions={view:'Görüntüleme',create:'Ekleme',reply:'Yanıt verme'};
 const legacy={shown:'admin_manager',client:'store_manager',all_roles:'admin_manager',store_roles:'store_manager',admin_logistics:'admin',accounting_logistics:'hidden'};
 for(const [stored,visible] of Object.entries(legacy)){
  config.pages.support=stored;
  const sections=permissionSections(catalog,config);
  assert.deepEqual([...sections.matchAll(/<option value="([^"]+)"/g)].map(([,key])=>key),['admin','store_manager','admin_manager','hidden']);
  assert.match(sections,new RegExp(`value="${visible}" selected`));
  assert.equal(config.pages.support,stored);
  assert.equal(sectionAllowsRole('support',stored,'accounting_logistics'),false);
  assert.equal(sectionHasStoreRole('support',stored),['admin_manager','store_manager'].includes(visible));
  assert.doesNotMatch(permissionMatrix(catalog,actions,config,'7'),/data-page="support"/);
 }
 config.pages.support='all_roles';
 assert.match(permissionMatrix(catalog,actions,config,'8'),/value="shown" selected/);
});

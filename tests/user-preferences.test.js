import test from 'node:test';
import assert from 'node:assert/strict';
import {applyPreferences,preferenceDefaults,themeLabels} from '../public/user-preferences.js';
import {settingsContent,settingsSections} from '../public/settings-view.js';
test('Kurumsal görünüm tüm hesaplarda ortaktır; erişilebilirlik tercihi korunur',()=>{
 const root={dataset:{}};applyPreferences({theme:'petrol',fontSize:'larger',fontFamily:'arial',density:'compact',reducedMotion:true},root);
 assert.equal(root.dataset.theme,'navy');assert.equal(root.dataset.fontFamily,'system');assert.equal(root.dataset.fontSize,'standard');assert.equal(root.dataset.density,'comfortable');assert.equal(root.dataset.reducedMotion,'true');applyPreferences(preferenceDefaults(),root);assert.equal(root.dataset.theme,'navy');assert.equal(root.dataset.density,'comfortable');
 applyPreferences({theme:'bad',fontSize:'bad'},root);assert.equal(root.dataset.theme,'navy');assert.equal(root.dataset.fontSize,'standard');
 applyPreferences({theme:'light'},root);assert.equal(root.dataset.theme,'light');assert.equal(root.dataset.density,'comfortable');
 applyPreferences(preferenceDefaults(),root);assert.equal(root.dataset.theme,'navy');
});
test('Yeni renk temaları iki rolün aynı tema seçicisinde kullanılabilir',()=>{
 const root={dataset:{}};
 for(const key of ['burgundy','petrol-smoke','graphite-copper']){
  applyPreferences({theme:key},root);
  assert.equal(root.dataset.theme,key);
  for(const role of ['admin','client']){
   const markup=settingsContent('theme',{role},{theme:key});
   assert.match(markup,new RegExp('value="'+key+'" selected>'+themeLabels[key]));
  }
 }
});
test('Her ayarın açıklaması vardır; gizlilik ve silme bağlantıları Hesap sekmesindedir',()=>{
 const user={role:'client',name:'<test>',email:'client@example.test'};
 assert.match(settingsContent('account',user,{}),/data-password-area/);assert.doesNotMatch(settingsContent('account',user,{}),/<test>/);
 assert.deepEqual(Object.keys(settingsSections),['account','sessions','tables','theme','language']);
 assert.match(settingsContent('theme',user,{theme:'light'}),/value="light" selected>Fildişi \(açık\)/);
 assert.match(settingsContent('theme',{role:'admin'},{}),/value="navy" selected>Lacivert \(varsayılan\)/);
 assert.match(settingsContent('tables',user,{shipmentLayouts:{1:'grouped'}},{id:1,name:'Örnek'}),/value="grouped" selected/);
 assert.equal(settingsContent('appearance',user,{}),'');assert.equal(settingsContent('history',user,{}),'');assert.match(settingsContent('sessions',user,{}),/data-settings-activity="sessions"/);
 assert.match(settingsContent('account',user,{}),/href="\/privacy.html"/);
 assert.match(settingsContent('account',user,{}),/href="\/account-deletion.html"/);
 for(const [label,description] of Object.values(settingsSections))assert.ok(label&&description);
});

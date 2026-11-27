import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {applyPreferences,preferenceDefaults,themeLabels} from '../public/user-preferences.js';
import {settingsContent,settingsSections} from '../public/settings-view.js';
import {menuStyles} from '../public/menu-appearance.js';

test('Dört menü stili iki rolde seçilir; renk ve tablo tercihinden bağımsızdır',()=>{
 for(const style of Object.keys(menuStyles)){
  const root={dataset:{}};applyPreferences({theme:'burgundy',menuStyle:style},root);
  assert.equal(root.dataset.menuStyle,style);assert.equal(root.dataset.theme,'burgundy');assert.equal(root.dataset.fontFamily,'system');
  for(const role of ['admin','client'])assert.ok(settingsContent('theme',{role},{menuStyle:style}).includes('value="'+style+'" selected'));
 }
 const root={dataset:{}};applyPreferences({menuStyle:'unknown'},root);assert.equal(root.dataset.menuStyle,'modern');
});
test('Kurumsal görünüm tüm hesaplarda ortaktır; erişilebilirlik tercihi korunur',()=>{
 const root={dataset:{}};applyPreferences({theme:'petrol',fontSize:'larger',fontFamily:'arial',density:'compact',reducedMotion:true},root);
 assert.equal(root.dataset.theme,'classic-navy');assert.equal(root.dataset.fontFamily,'system');assert.equal(root.dataset.fontSize,'standard');assert.equal(root.dataset.density,'comfortable');assert.equal(root.dataset.reducedMotion,'true');applyPreferences(preferenceDefaults(),root);assert.equal(root.dataset.theme,'classic-navy');assert.equal(root.dataset.density,'comfortable');
 applyPreferences({theme:'bad',fontSize:'bad'},root);assert.equal(root.dataset.theme,'classic-navy');assert.equal(root.dataset.fontSize,'standard');
 applyPreferences({theme:'light'},root);assert.equal(root.dataset.theme,'light');assert.equal(root.dataset.density,'comfortable');
 applyPreferences({theme:'parliament'},root);assert.equal(root.dataset.theme,'classic-navy');
 applyPreferences(preferenceDefaults(),root);assert.equal(root.dataset.theme,'classic-navy');
});
test('Klasik lacivert ve diğer renk temaları iki rolün aynı tema seçicisinde kullanılabilir',()=>{
 const root={dataset:{}};
 for(const key of ['classic-navy','burgundy','petrol-smoke','graphite-copper']){
  applyPreferences({theme:key},root);
  assert.equal(root.dataset.theme,key);
  for(const role of ['admin','client']){
   const markup=settingsContent('theme',{role},{theme:key});
   assert.ok(markup.includes('value="'+key+'" selected>'+themeLabels[key]));
  }
 }
});
test('Eski Parliament yüzeyi yalnız geriye dönük varlık olarak tutulur',()=>{
 const welcome=readFileSync(new URL('../public/welcome-screen.css',import.meta.url),'utf8');
 const loader=readFileSync(new URL('../public/portal-styles.js',import.meta.url),'utf8');
 const theme=readFileSync(new URL('../public/parliament-theme.css',import.meta.url),'utf8');
 assert.match(welcome,/parliament-theme\.css/);
 assert.match(loader,/parliament-theme\.css/);
 assert.match(theme,/:root\[data-theme=parliament\]/);
 assert.doesNotMatch(theme,/font-size\s*:/);
});
test('Her ayarın açıklaması vardır; gizlilik ve silme bağlantıları Hesap sekmesindedir',()=>{
 const user={role:'client',name:'<test>',email:'client@example.test'};
 assert.match(settingsContent('account',user,{}),/data-password-area/);assert.doesNotMatch(settingsContent('account',user,{}),/<test>/);
 assert.deepEqual(Object.keys(settingsSections),['account','sessions','tables','theme','language']);
 assert.match(settingsContent('theme',user,{theme:'light'}),/value="light" selected>Fildişi \(açık\)/);
 assert.match(settingsContent('theme',{role:'admin'},{}),/value="classic-navy" selected>Omnigo Klasik Lacivert \(varsayılan\)/);
 assert.match(settingsContent('tables',user,{shipmentLayouts:{1:'grouped'}},{id:1,name:'Örnek'}),/value="grouped" selected/);
 assert.equal(settingsContent('appearance',user,{}),'');assert.equal(settingsContent('history',user,{}),'');assert.match(settingsContent('sessions',user,{}),/data-settings-activity="sessions"/);
 assert.match(settingsContent('account',user,{}),/href="\/privacy.html"/);
 assert.match(settingsContent('account',user,{}),/href="\/account-deletion.html"/);
 for(const [label,description] of Object.values(settingsSections))assert.ok(label&&description);
});

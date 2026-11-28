import test from 'node:test';import assert from 'node:assert/strict';
import {settingsContent,settingsSections} from '../public/settings-view.js';
import {workspaceMenuLabelText} from '../public/workspace-menu-labels.js';

test('Tablo ayarı seçili mağazanın gerçek menü adını kullanır; kayıtlı düzeni değiştirmez',()=>{
 for(const [company,custom,preset] of [[1,null,null],[2,null,'customer-standard'],[4,'Teslim evrakları <Özel>','customer-standard']]){
  const state={company,contextRevision:0,user:{role:'client'},data:{workspaceAccess:{interfacePreset:preset}},...(custom?{workspaceMenuLabels:{company,context:0,labels:{shipments:custom}}}:{})};
  const preferences={shipmentLayouts:{[company]:'grouped'}},before=JSON.stringify(preferences);
  const label=workspaceMenuLabelText(state,'shipments'),markup=settingsContent('tables',state.user,preferences,{id:company,name:'Örnek mağaza'},label);
  assert(markup.includes('<h3>'+label.replaceAll('<','&lt;').replaceAll('>','&gt;')+'</h3>'));
  assert.match(markup,/value="grouped" selected/);assert.equal(JSON.stringify(preferences),before);
  if(company===2)assert.equal(label,'Kargo Fişleri');
  if(company===1)assert.equal(label,'Şehir Dışı Sevkiyatlar');
 }
 assert.doesNotMatch(settingsSections.tables[1],/Şehir Dışı Sevkiyat/);
});

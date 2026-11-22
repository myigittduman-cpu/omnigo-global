import test from 'node:test';
import assert from 'node:assert/strict';
import {workspaceMenuLabelMarkup,workspaceMenuLabelText,applyWorkspaceMenuLabels} from '../public/workspace-menu-labels.js';
import {permissionSections,permissionMatrix} from '../public/customer-permissions-view.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';

const state={company:1,contextRevision:2,user:{id:1,role:'admin'},workspaceMenuLabels:{company:1,context:2,revision:1,labels:{inbox:'ABC soruları',payments:'Ödeme görünümü',inventory:'<img src=x>'}}};
test('Menü, sayfa ve yetki başlığı aynı anahtardan ad alır; HTML çalıştırılmaz',()=>{
 const navigation=workspaceNavigation({inbox:'Müşteriyle İletişim',payments:'Aktif hakedişler',inventory:'Stok Yönetimi'},state);
 assert.match(navigation,/>ABC soruları</);assert.match(navigation,/>Ödeme görünümü</);
 assert.match(navigation,/&lt;img src=x&gt;/);assert.doesNotMatch(navigation,/<img src=x>/);
 assert.match(workspaceMenuLabelMarkup(state,'inbox','Müşteriyle İletişim'),/>ABC soruları</);
 const configuration={pages:{payments:'shown'},users:{7:{payments:{view:true}}},roles:{7:'store_manager'}};
 const catalog={payments:{label:'Hakedişler',actions:['view']}};
 assert.match(permissionSections(catalog,configuration,{state}),/>Ödeme görünümü</);
 assert.match(permissionMatrix(catalog,{},configuration,'7',{state}),/>Ödeme görünümü</);
 assert.equal(workspaceMenuLabelText({...state,company:2},'payments','Hakedişler'),'Hakedişler');
});
test('Ad güncellemesi açık formu veya menü düğümünü yeniden oluşturmaz; eski sürüm reddedilir',()=>{
 const caption={dataset:{workspaceMenuLabel:'inbox',workspaceMenuDefault:'Pazaryeri Soruları'},textContent:'Pazaryeri Soruları',toggleAttribute(){}};
 const input={value:'Kaydedilmemiş yanıt',checked:true};let events=0;
 const root={querySelectorAll:()=>[caption],ownerDocument:{dispatchEvent(){events++;}}};
 const current={company:1,contextRevision:0,user:{id:1,role:'admin'}};
 assert(applyWorkspaceMenuLabels(current,root,{id:1,menuLabels:{version:10,labels:{inbox:'Yeni ad'}}}));
 assert.equal(caption.textContent,'Yeni ad');assert.equal(input.value,'Kaydedilmemiş yanıt');assert(input.checked);
 assert.equal(applyWorkspaceMenuLabels(current,root,{id:1,menuLabels:{version:9,labels:{inbox:'Eski ad'}}}),false);
 assert.equal(applyWorkspaceMenuLabels(current,root,{id:2,menuLabels:{version:11,labels:{inbox:'Diğer mağaza'}}}),false);
 assert.equal(events,1);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {roleDefaultsView} from '../public/application-role-defaults.js';
import {customerCan,customerPageVisible} from '../public/customer-access.js';
import {permissionMatrix} from '../public/customer-permissions-view.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';
import {returnsView} from '../public/returns-view.js';

const actions={view:'Görüntüleme',viewCancelledRecords:'İptal kayıtlarını görme',viewReturnedRecords:'İade kayıtlarını görme',
 viewAfterCancellationTurnover:'İptal sonrası ciroyu görme'};
const permissionsCatalog={
 analytics:{label:'Analiz ve raporlar',actions:Object.keys(actions)},
 returns:{label:'İptal & İade Talepleri',actions:['view','viewCancelledRecords','viewReturnedRecords']},
};
const grants={analytics:{view:true,viewCancelledRecords:false,viewReturnedRecords:false,viewAfterCancellationTurnover:false},
 returns:{view:false,viewCancelledRecords:false,viewReturnedRecords:false}};
const pages={analytics:'shown',returns:'shown'};

test('Rol varsayılanı ve kullanıcı ayarı aynı tablo seçeneklerini kapalı gösterir',()=>{
 const roles={store_manager:{label:'Mağaza Yetkilisi',version:0,grants}};
 const roleView=roleDefaultsView({roles,permissionsCatalog,actions});
 for(const action of Object.keys(actions).slice(1)){
  assert.match(roleView,new RegExp(`name="analytics\\.${action}"[^>]+data-role-default-action="${action}"\\s+>`));
 }
 assert.match(roleView,/name="returns\.viewReturnedRecords"[^>]+disabled/);
 const view=permissionMatrix(permissionsCatalog,actions,{pages,defaults:grants,users:{},roles:{}},'7');
 assert.match(view,/data-page="analytics" data-permission="viewAfterCancellationTurnover"\s+>/);
 assert.match(view,/Yalnızca bu tablodaki iptal sonrası ciroyu gösterir/);
});

test('İptal & İade Talepleri için ekran ve belirli işlem izni gerekir',()=>{
 const scoped=structuredClone(grants);
 scoped.returns.view=true;
 const state={user:{role:'client'},data:{workspaceAccess:{pages:{...pages},grants:scoped}}};
 assert.equal(customerPageVisible(state,'returns'),false);
 const menu=()=>workspaceNavigation({returns:'İptal & İade Talepleri'},state);
 assert.doesNotMatch(menu(),/data-nav="returns"/);
 scoped.returns.viewReturnedRecords=true;
 assert.equal(customerPageVisible(state,'returns'),true);
 assert.match(menu(),/data-nav="returns"/);
 assert.equal(customerCan(state,'returns','viewReturnedRecords'),true);
 assert.equal(customerCan(state,'returns','viewCancelledRecords'),false);
 assert.equal(customerCan(state,'analytics','viewReturnedRecords'),false);
 state.data.workspaceAccess.pages.returns='hidden';
 assert.equal(customerPageVisible(state,'returns'),false);
});

test('Talep filtresi yalnızca yetkili türü gösterir',()=>{
 const view=returnsView({type:'cancellation',types:['cancellation'],page:0,data:{items:[],sources:[]}});
 assert.match(view,/option value="cancellation" selected/);
 assert.doesNotMatch(view,/option value="return"|Tüm talepler|Aktarım kapsamı/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {adminCanOpenHiddenPage} from '../public/admin-hidden-menus.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';
const names={overview:'Genel bakış',channels:'Panel satışları',inventory:'Stok Yönetimi',shipments:'Şehir Dışı Sevkiyat',payments:'Hakedişlerim'};
const state=role=>({user:{role},view:'inventory',data:{modules:{shipments:false},workspaceAccess:{pages:{overview:'shown',channels:'hidden',inventory:'hidden',shipments:'hidden',payments:'shown'},grants:{inventory:{view:false},payments:{view:true},overview:{view:true}}}}});
test('Her iki rolde de Gizlenen menüler bölümü ve gizli sekmeler ana menüden çıkarılır',()=>{
 for(const role of ['admin','client']){
  const workspace=state(role),before=structuredClone(workspace.data.workspaceAccess);
  const html=workspaceNavigation(names,workspace);
  assert.doesNotMatch(html,/data-hidden-menus|Gizlenen menüler|data-nav="inventory"/);
  assert.match(html,/data-nav="payments"/);
  assert.deepEqual(workspace.data.workspaceAccess,before);
 }
 assert.equal(adminCanOpenHiddenPage(state('client'),'inventory'),false);
});
test('Kurulumda kapalı bölümler açılmaz; mevcut admin erişimi kullanılır',()=>{
 const admin=state('admin');assert.equal(adminCanOpenHiddenPage(admin,'inventory'),true);assert.equal(adminCanOpenHiddenPage(admin,'shipments'),false);admin.data.modules.shipments=true;assert.equal(adminCanOpenHiddenPage(admin,'shipments'),true);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {marketplaceAccessCompany} from '../public/marketplace-access-policy.js';
import {managementGrant,rememberManagementGrant,clearManagementGrants} from '../public/management-access-state.js';
import {protectManagementDialog} from '../public/management-access-flow.js';
const proof='a'.repeat(64);
test('Üç alan, kullanıcı yönetimi ve dosya yüklemeleri doğru koruma bağlamına bağlıdır',()=>{
 for(const path of ['/management/permissions','/management/customer','/management/marketplaces','/management/initial-password','/management/users/8/password'])assert.equal(marketplaceAccessCompany(path+'?company=2'),2);
 assert.equal(marketplaceAccessCompany('/admin/users/8/portrait','POST'),0);
 assert.equal(marketplaceAccessCompany('/users','POST',{companyId:2}),2);
 assert.equal(marketplaceAccessCompany('/management/logo?company=2','POST'),2);
 assert.equal(marketplaceAccessCompany('/management/logo?company=2'),null);
 assert.equal(marketplaceAccessCompany('/admin/workspace-access?company=2'),2);
 for(const path of ['/push/desktop/poll','/inventory?company=2','/workspace/revision?company=2'])assert.equal(marketplaceAccessCompany(path),null);
});
test('X/Escape kapatması sunucudaki 15 dakikalık izni iptal etmez',()=>{
 clearManagementGrants();const dialog=new EventTarget();protectManagementDialog(dialog);protectManagementDialog(dialog);
 rememberManagementGrant(2,{grant:proof});assert.equal(managementGrant(2),proof);assert.equal(managementGrant(3),'');
 dialog.dispatchEvent(new Event('omnigo-dialog-dismiss'));assert.equal(managementGrant(2),proof);dialog.dispatchEvent(new Event('close'));assert.equal(managementGrant(2),proof);
});
test('Alt bölüm, kayıt, kapanma ve yeniden açılma süreyi uzatmaz veya iptal etmez',()=>{
 const dialog=new EventTarget();protectManagementDialog(dialog);rememberManagementGrant(2,{grant:proof});
 dialog.dispatchEvent(new Event('omnigo-dialog-navigation'));dialog.dispatchEvent(new Event('close'));assert.equal(managementGrant(2),proof);
 dialog.dispatchEvent(new Event('omnigo-management-saved'));dialog.dispatchEvent(new Event('close'));assert.equal(managementGrant(2),proof);
 dialog.dispatchEvent(new Event('close'));assert.equal(managementGrant(2),proof);
});
test('Genel kullanıcı yönetimi aynı şifreyi alt bölümlerde kullanır; oturum sıfırlaması unutulur',()=>{
 rememberManagementGrant(0,{grant:proof});assert.equal(managementGrant(3),proof);clearManagementGrants();assert.equal(managementGrant(3),'');
 assert.throws(()=>rememberManagementGrant(1,{grant:'bad'}));
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {marketplaceAccessCompany} from '../public/marketplace-access-policy.js';
import {managementGrant,rememberManagementGrant,clearManagementGrants} from '../public/management-access-state.js';
const proof='a'.repeat(64);
test('Üç alan, kullanıcı yönetimi ve dosya yüklemeleri doğru koruma bağlamına bağlıdır',()=>{
 for(const path of ['/management/permissions','/management/customer','/management/marketplaces','/management/initial-password','/management/users/8/password'])assert.equal(marketplaceAccessCompany(path+'?company=2'),2);
 assert.equal(marketplaceAccessCompany('/admin/users/8/portrait','POST'),0);
 assert.equal(marketplaceAccessCompany('/users','POST',{companyId:2}),2);
 assert.equal(marketplaceAccessCompany('/management/logo?company=2','POST'),2);
 assert.equal(marketplaceAccessCompany('/management/logo?company=2'),null);
 for(const path of ['/admin/workspace-access?company=2','/management/setup/customer?company=2','/management/permission-template','/management/new-records?company=2','/management/marketplaces/start?company=2','/push/desktop/poll','/inventory?company=2','/workspace/revision?company=2'])assert.equal(marketplaceAccessCompany(path),null);
});
test('Pencerede üç alan için bir ortak doğrulama kullanılır, oturum kapatılınca temizlenir',()=>{
 rememberManagementGrant({grant:proof});assert.equal(managementGrant(),proof);
 clearManagementGrants();assert.equal(managementGrant(),'');
 assert.throws(()=>rememberManagementGrant({grant:'bad'}));
});

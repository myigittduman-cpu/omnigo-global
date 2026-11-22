import test from 'node:test';
import assert from 'node:assert/strict';
import {customPageItems,customPageKey} from '../public/custom-page-navigation.js';
import {customerCan} from '../public/customer-access.js';
import {customerPageNames} from '../public/customer-modules.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';
import {workspaceFocusCategory} from '../public/workspace-focus-model.js';
import {pageActionUrl} from '../public/page-action-url.js';
import {api as demoApi} from '../public/demo/api.js';
import {company,user} from '../public/demo/data.js';

const pages=[{slug:'rehber',title:'Rehber <img>',summary:'Örnek',menuGroup:'operations',role:'both',scope:'all',companyIds:[]},{slug:'yonetim',title:'Yönetim',menuGroup:'management',role:'admin',scope:'all',companyIds:[]},{slug:'magaza',title:'Mağaza',menuGroup:'finance',role:'client',scope:'selected',companyIds:[1]}];
const state=(role='client',id=1)=>({user:{id:10,role,companyId:role==='client'?id:null},company:id,view:'overview',data:{customPages:{items:pages},workspaceAccess:{pages:{overview:'shown'},grants:{overview:{view:true}}}}});

test('özel sayfalar mevcut modül izinlerini genişletmeden rol ve mağazaya göre görünür',()=>{
 const client=state();assert.deepEqual(customPageItems(client).map(p=>p.slug),['rehber','magaza']);
 const key=customPageKey(pages[0]);assert.equal(customerCan(client,key),true);assert.equal(customerCan(client,key,'edit'),false);
 assert.equal(customerCan(client,'customers'),false);
 assert.equal(customerCan(client,customPageKey(pages[1])),false);
 assert.equal(customPageItems({...client,company:2}).length,0);
 assert.deepEqual(customPageItems(state('client',2)).map(p=>p.slug),['rehber']);
 assert.deepEqual(customPageItems(state('admin')).map(p=>p.slug),['rehber','yonetim']);
});

test('yayımlanan sayfanın adı ve seçilen menü grubu masaüstü ve mobil ortak menüsüne girer',()=>{
 const client=state(),names=customerPageNames({overview:'Genel bakış'},client),key=customPageKey(pages[0]);
 assert.equal(names[key],pages[0].title);assert.equal(workspaceFocusCategory(key),'operations');
 const html=workspaceNavigation(names,client,true);
 const operations=html.split('data-menu-group="operations"')[1]?.split('</section>')[0];
 assert.ok(operations.includes(`data-nav="${key}"`));assert.match(operations,/Rehber &lt;img&gt;/);assert.doesNotMatch(operations,/<img>/);
 assert.doesNotMatch(html,/customPage.management.yonetim/);
});

test('güvensiz bağlantılar istemcide de reddedilir',()=>{
 for(const url of ['//other.example','/\\other.example','javascript:alert(1)','https://user:pass@example.test','https://','/bad\npath'])assert.equal(pageActionUrl(url),'');
 for(const url of ['/rehber','https://example.test/help'])assert.equal(pageActionUrl(url),url);
});

test('demo özel sayfaları sentetiktir, yazma ve mağaza dışı okumalar kapalıdır',async()=>{
 const fetch=globalThis.fetch;globalThis.fetch=()=>{throw Error('Gerçek ağ yasak');};
 try{
  const result=await demoApi('/custom-pages?company='+company.id);
  assert.equal(customPageItems({user,company:company.id,data:{customPages:result}}).length,2);
  result.items[0].title='Değiştirildi';assert.notEqual((await demoApi('/custom-pages')).items[0].title,'Değiştirildi');
  for(const [route,method] of [['/account/page-builder','GET'],['/account/page-builder','PUT'],['/account/page-builder/publish','POST'],['/custom-pages?company=1','GET']])await assert.rejects(demoApi(route,method,{}),error=>error.status===403);
 }finally{globalThis.fetch=fetch;}
});

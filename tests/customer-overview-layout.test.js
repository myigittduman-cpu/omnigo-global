import test from 'node:test';
import assert from 'node:assert/strict';
import {usesCustomerOverviewTabs,customerOverviewTarget} from '../public/customer-overview-layout.js';
import {customerPageNames} from '../public/customer-modules.js';
import {overviewSectionOptions,overviewSections} from '../public/overview-sections.js';
import {content} from '../public/views.js';

const names={overview:'Genel bakış',overviewSales:'Pazaryeri Satışları',overviewTop:'En Çok Satan 10',overviewWork:'İşlem Bekleyenler',orders:'Siparişler'};
function state(layout='tabs'){
 const pages=Object.fromEntries([...Object.keys(names),'deliveries'].map(page=>[page,'shown']));
 const grants=Object.fromEntries(Object.keys(pages).map(page=>[page,{view:true}]));
 return {company:2,month:'2026-11',view:'overview',user:{id:5,role:'client'},data:{channels:[],sales:[],payments:[],summary:{},workspaceAccess:{overviewLayout:layout,pages,grants}}};
}

test('Mağaza tercihi üç ayrı bağlantıyı aynı yetkilerle Genel Bakış sekmelerine taşır',()=>{
 const s=state(),before=JSON.stringify(s);
 assert(usesCustomerOverviewTabs(s));
 assert.deepEqual(Object.keys(customerPageNames(names,s)),['overview','orders']);
 assert.deepEqual(Object.keys(overviewSectionOptions(s)),['summary','sales','top','work']);
 assert.deepEqual(['overviewSales','overviewTop','overviewWork'].map(p=>customerOverviewTarget(s,p)),['sales','top','work']);
 const result=content(s);
 for(const key of ['summary','sales','top','work'])assert.match(result,new RegExp('data-overview-tab="'+key+'"'));
 assert.equal((result.match(/data-customer-work /g)||[]).length,1);
 assert.equal(JSON.stringify(s),before);
});

test('Gizli veya yetkisi kapalı bölüm sekme ve eski bağlantıyla açılamaz',()=>{
 const s=state();s.data.workspaceAccess.grants.overviewTop.view=false;
 s.data.workspaceAccess.pages.overviewSales='hidden';
 s.overviewSections={company:2,tab:'top'};
 assert.deepEqual(Object.keys(overviewSectionOptions(s)),['summary','work']);
 assert.equal(customerOverviewTarget(s,'overviewTop'),null);
 assert.equal(customerOverviewTarget(s,'overviewSales'),null);
 const result=content(s);
 assert.doesNotMatch(result,/overview-(?:tab|panel)-(?:top|sales)|data-overview-top-channel|monthlySalesTitle/);
 assert.match(result,/data-overview-tab="summary" aria-selected="true"/);
});

test('Vizyona, diğer mağazalar ve yönetici hesabının önceki düzeni korunur',()=>{
 const s=state('separate');s.company=1;
 assert(!usesCustomerOverviewTabs(s));
 assert.deepEqual(Object.keys(customerPageNames(names,s)),Object.keys(names));
 assert.equal(customerOverviewTarget(s,'overviewSales'),null);
 assert.doesNotMatch(content(s),/data-overview-tab=/);
 const admin={...state(),user:{id:1,role:'admin'}};
 assert(!usesCustomerOverviewTabs(admin));
 assert.deepEqual(Object.keys(overviewSectionOptions(admin)),['summary','sales','top','attention','work']);
});

test('Genel Bakış sonradan kapatılırsa bağımsız yetkili bölümler kaybolmaz',()=>{
 const s=state();s.data.workspaceAccess.grants.overview.view=false;
 assert(!usesCustomerOverviewTabs(s));
 assert.deepEqual(Object.keys(customerPageNames(names,s)),['overviewSales','overviewTop','overviewWork','orders']);
 const result=overviewSections(state(),{summary:'ÖZET',sales:'SATIŞ'});
 assert.match(result,/overview-section-tabs--customer/);
});

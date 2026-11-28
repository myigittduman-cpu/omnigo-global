import test from 'node:test';
import assert from 'node:assert/strict';
import {customerWorkspaceGroups,usesCustomerWorkspaceStandard} from '../public/customer-workspace-standard.js';
import {workspaceFocusGroups} from '../public/workspace-focus-model.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';
import {customerCan} from '../public/customer-access.js';
import {shipmentCapabilities} from '../public/shipment-permissions.js';
import {workspaceMenuLabelText} from '../public/workspace-menu-labels.js';

const groups={management:['overview','fileCenter','orders'],operations:['returns','inventory','deliveries','shipments'],finance:['financeProfitability','analytics','payments'],system:['support']};
const names=Object.fromEntries([...Object.values(groups).flat(),'overviewSales','overviewTop','overviewWork','tasks','reports','channels','cityProducts'].map(p=>[p,p]));
function state(role='store_manager'){
 const visible=[...Object.values(groups).flat(),'overviewSales','overviewTop','overviewWork','shipmentInvoices'];
 const pages=Object.fromEntries(Object.keys(names).concat('shipmentInvoices').map(p=>[p,visible.includes(p)?'shown':'hidden']));
 const grants=Object.fromEntries(Object.keys(pages).map(p=>[p,{view:visible.includes(p)}]));
 Object.assign(grants.shipments,{selectRows:true,invoice:true,documents:true,upload:true,export:true,create:false,edit:false,delete:false,status:false,bulkStatus:false,replaceDocuments:false,deleteDocuments:false});
 grants.shipmentInvoices={view:true,documents:true,upload:true,replaceDocuments:false};
 grants.support={view:true,create:true,reply:false,status:false};
 grants.returns={view:true,viewCancelledRecords:true,viewReturnedRecords:true};
 return {company:2,view:'overview',user:{id:5,role:'client'},data:{workspaceAccess:{interfacePreset:'customer-standard',overviewLayout:'tabs',pages,grants,storeRole:role}}};
}

test('Standart müşteride yalnız 11 bağlantı istenen dört grupta yer alır',()=>{
 for(const role of ['store_manager','accounting_logistics','store_admin']){
  const s=state(role),before=JSON.stringify(s),markup=workspaceNavigation(names,s);
  const actual=Object.fromEntries([...markup.matchAll(/<section class="workspace-directory-group" data-menu-group="([^"]+)"[\s\S]*?<\/section>/g)].map(m=>[m[1],[...m[0].matchAll(/data-nav="([^"]+)"/g)].map(v=>v[1])]));
  assert.deepEqual(actual,groups);assert.match(markup,/Kargo Fişleri/);
  assert.equal(workspaceMenuLabelText(s,'shipments'),'Kargo Fişleri');
  assert.equal(JSON.stringify(s),before);
 }
});

test('Standart rollerin destek oluşturması açık, ek işlem yetkileri kapalıdır',()=>{
 const s=state('accounting_logistics');
 assert(customerCan(s,'support'));assert(customerCan(s,'support','create'));
 assert(!customerCan(s,'support','reply'));assert(!customerCan(s,'support','status'));
 const caps=shipmentCapabilities(s);
 for(const flag of ['canCreate','canEdit','canDelete','canConfirm','canBulkStatus','canReplaceDocuments','canDeleteDocuments'])assert.equal(caps[flag],false,flag);
 for(const flag of ['canInvoice','canDocuments','canUpload','canShare','canSelect'])assert.equal(caps[flag],true,flag);
});

test('Vizyona ve yönetici grupları, adları ve rol sınırları değişmez',()=>{
 const s=state('accounting_logistics');delete s.data.workspaceAccess.interfacePreset;s.company=1;
 assert(!usesCustomerWorkspaceStandard(s));assert.equal(customerWorkspaceGroups(s,workspaceFocusGroups),workspaceFocusGroups);
 assert.equal(customerWorkspaceGroups({...state(),user:{role:'admin'}},workspaceFocusGroups),workspaceFocusGroups);
 assert.equal(customerCan(s,'support'),false);
 assert.equal(workspaceMenuLabelText(s,'shipments'),'Şehir Dışı Sevkiyatlar');
 const labeled=state();labeled.workspaceMenuLabels={company:2,context:0,labels:{shipments:'Özel kargo adı'}};
 assert.equal(workspaceMenuLabelText(labeled,'shipments'),'Özel kargo adı');
});

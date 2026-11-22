import assert from 'node:assert/strict';
import test from 'node:test';
import {workspaceExportCompany} from '../public/workspace-export-company.js';
import {deliveryExportCompany} from '../public/company-export-brand.js';

test('Yalnızca seçili mağazanın en güncel logo bilgisi dışa aktarılır',()=>{
 const company={id:12,name:'Örnek Mağaza',hasLogo:true,brandingVersion:7};
 const other={id:13,name:'Diğer Mağaza',hasLogo:true,brandingVersion:20};
 const state={company:12,data:{company:{id:12,name:'Örnek Mağaza'}},companies:[other,company]};
 assert.deepEqual(workspaceExportCompany(state,12),company);
 state.data.company={...company,hasLogo:false,brandingVersion:6};
 assert.deepEqual(workspaceExportCompany(state,12),company);
 state.data.company={...company,hasLogo:false,brandingVersion:8};
 assert.equal(workspaceExportCompany(state,12).hasLogo,false);
 assert.equal(workspaceExportCompany(state,13),undefined);
 state.user={role:'client',companyId:13};
 assert.equal(workspaceExportCompany(state,12),undefined);
});

test('Teslimat dosyası kendi mağazasının logo sürümünü ve göndericinin logo kaldırma bilgisini korur',()=>{
 const company={id:12,name:'Örnek Mağaza',hasLogo:true,brandingVersion:7};
 const snapshot={companyId:12,sender:{name:'Yasal Gönderici'}};
 const output=deliveryExportCompany(snapshot,{company});
 assert.deepEqual(output,company);
 assert.equal(snapshot.sender.name,'Yasal Gönderici');
 snapshot.sender.hasLogo=false;
 assert.equal(deliveryExportCompany(snapshot,{company}).hasLogo,false);
 assert.throws(()=>deliveryExportCompany(snapshot,{company:{name:'Kimliği eksik'}}));
});

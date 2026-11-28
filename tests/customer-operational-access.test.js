import assert from 'node:assert/strict';
import test from 'node:test';
import {customerCan} from '../public/customer-access.js';
import {workspaceNavigation} from '../public/workspace-navigation.js';
import {financeReportContent} from '../public/finance-report.js';
import {fileCenterArchive} from '../public/file-center-view.js';

test('Dosya Merkezi ve Kârlılık Analizi yalnız açık izinle müşteri menüsüne girer',()=>{
 const state={user:{id:5,role:'client'},company:2,view:'financeProfitability',data:{workspaceAccess:{pages:{fileCenter:'shown',financeProfitability:'shown'},grants:{fileCenter:{view:true,create:true,delete:false,export:false},financeProfitability:{view:true,export:false}}}}};
 const menu=workspaceNavigation({fileCenter:'Dosya Merkezi',financeProfitability:'Kârlılık Analizi'},state,true);
 assert.match(menu,/data-nav="fileCenter"/);assert.match(menu,/data-nav="financeProfitability"/);
 assert.equal(customerCan(state,'fileCenter','delete'),false);
 const content=financeReportContent(state);assert.match(content,/data-reporting="financeProfitability"/);assert.doesNotMatch(content,/data-profit-output|data-finance-rates/);
 state.data.workspaceAccess.grants.financeProfitability.export=true;
 assert.match(financeReportContent(state),/data-profit-output/);
 state.data.workspaceAccess.pages.financeProfitability='hidden';assert.equal(financeReportContent(state),'');
 assert.equal(customerCan({user:{role:'client'}},'fileCenter'),false);
 assert.equal(customerCan({user:{role:'client'}},'financeProfitability'),false);
});

test('Hazırlanan dosya çıktısı kapalı kullanıcıda indirme, paylaşma ve yazdırma görünmez',()=>{
 const data={items:[{id:1,name:'Örnek.pdf',type:'pdf',category:'stock',size:1,createdAt:'2026-11-28'}],page:0,pages:1,total:1};
 assert.match(fileCenterArchive(data),/data-file-center-download/);
 assert.doesNotMatch(fileCenterArchive(data,{canExport:false}),/data-file-center-download|data-archive-share|data-archive-print/);
});

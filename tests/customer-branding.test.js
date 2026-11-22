import test from 'node:test';
import assert from 'node:assert/strict';
import {applyCustomerBranding} from '../public/customer-branding.js';
import {companyIdentity} from '../public/workspace-entry.js';
test('Açık portalda kimlik değişir; başka müşteri, eski yanıt ve açık form korunur',()=>{
 const company={id:1,name:'Eski',hasLogo:true,brandingVersion:2},other={id:2,name:'Diğer',brandingVersion:1};
 const state={company:1,companies:[company,other]},name={dataset:{companyName:'1'},textContent:'Eski'},logo={dataset:{companyLogo:'1'},innerHTML:''},otherName={dataset:{companyName:'2'},textContent:'Diğer'};
 const root={querySelectorAll:selector=>selector==='[data-company-name]'?[name,otherName]:[logo]};
 assert.equal(applyCustomerBranding(state,root,{id:2,name:'Yanlış',brandingVersion:4}),false);
 assert.equal(applyCustomerBranding(state,root,{id:1,name:'Yeni & firma',hasLogo:true,brandingVersion:3}),true);
 assert.equal(name.textContent,'Yeni & firma');assert.match(logo.innerHTML,/&v=3/);assert.equal(otherName.textContent,'Diğer');
 assert.equal(applyCustomerBranding(state,root,{id:1,name:'Gecikmiş',brandingVersion:2}),false);
 assert.equal(company.name,'Yeni & firma');assert.equal(applyCustomerBranding(state,root,{...company}),false);
 assert.equal(applyCustomerBranding(state,root,{id:1,name:'Son',hasLogo:false,brandingVersion:4}),true);assert.equal(logo.innerHTML,'<span>SO</span>');
});
test('Logo adresi sürümlenir ve müşteri adı HTML olarak çalıştırılmaz',()=>{
 assert.match(companyIdentity({id:1,hasLogo:true,name:'<img>',brandingVersion:8}),/&v=8/);
 assert.match(companyIdentity({id:1,hasLogo:true,name:'<img>',brandingVersion:8}),/&lt;img&gt;/);
});

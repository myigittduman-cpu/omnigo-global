import test from 'node:test';
import assert from 'node:assert/strict';
import {workspaceCompany} from '../public/workspace-company.js';
import {bindCustomerSelector} from '../public/customer-selector.js';

test('Üst menü seçicisi yalnız oturumun mağazalarını gösterir ve adları güvenli yazar',()=>{
 const companies=[{id:1,name:'Bir'},{id:2,name:'İki <Mağaza>'}];
 const html=workspaceCompany(companies[0],{companies,canSwitch:true});
 assert.match(html,/aria-label="Mağaza seç"/);assert.match(html,/value="1" selected/);
 assert.match(html,/İki &lt;Mağaza&gt;/);assert.equal((html.match(/<option/g)||[]).length,2);
 assert.doesNotMatch(workspaceCompany(companies[0],{companies,canSwitch:false}),/<select|İki/);
 assert.doesNotMatch(workspaceCompany({id:3,name:'Yok'},{companies,canSwitch:true}),/<select/);
});
test('Tüm sayfalarda seçiciler aynı korumalı değişimi bekler; iptalde birlikte eski değere döner',async()=>{
 const state={user:{role:'admin'},view:'shipments',company:1};let finish,calls=0;
 const controls=[0,1].map(()=>({value:'1',isConnected:true,disabled:false,addEventListener(type,handler){this.change=handler;}}));
 bindCustomerSelector({querySelectorAll:()=>controls},state,()=>{calls++;return new Promise(resolve=>finish=resolve);});
 controls[0].value='2';const pending=controls[0].change();
 assert.ok(controls.every(control=>control.disabled));await controls[1].change();assert.equal(calls,1);
 finish(false);await pending;assert.ok(controls.every(control=>control.value==='1'&&!control.disabled));
 assert.equal(state.view,'shipments');
});

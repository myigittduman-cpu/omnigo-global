import test from 'node:test';
import assert from 'node:assert/strict';
import {workspaceFrameKey} from '../public/workspace-frame.js';
const base={user:{id:3,role:'admin',name:'Örnek Omnigo',avatarVersion:4},company:1,contextRevision:2,view:'orders'};
const company={id:1,name:'Örnek mağaza'},names={orders:'Siparişler',inventory:'Stok Yönetimi'};
const key=(state=base,brand=company,nav=names,language='tr')=>workspaceFrameKey(state,brand,nav,language);
test('Yalnız sayfa, dönem veya veri yenilenince ortak çerçeve korunur',()=>{
 assert.equal(key(),key({...base,view:'inventory',month:'2026-11',data:{revision:8}}));
});
test('Farklı hesap, müşteri, yetki görünürlüğü, dil ve profil ortak çerçeveyi paylaşmaz',()=>{
 for(const state of [{...base,company:2},{...base,contextRevision:3},{...base,user:{...base.user,id:4}},{...base,user:{...base.user,role:'client'}},{...base,user:{...base.user,avatarVersion:5}}])assert.notEqual(key(),key(state));
 assert.notEqual(key(),key(base,{id:1,name:'Yeni marka'}));assert.notEqual(key(),key(base,company,{orders:'Siparişler'}));assert.notEqual(key(),key(base,company,names,'en'));
});

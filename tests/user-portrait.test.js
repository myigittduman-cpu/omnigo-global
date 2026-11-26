import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {adminPortraitUrl,portraitUrl,userPortraitMarkup} from '../public/user-portrait.js';
import {portraitUrl as directoryPortraitUrl,accountDirectoryRows,accountDirectoryPortrait,accountDirectoryForm} from '../public/account-directory-view.js';
import {applyCustomerBranding} from '../public/customer-branding.js';

test('Mevcut ve yeni yöneticiler kişisel resimden ve seçili mağazadan bağımsız aynı onaylı logoyu gösterir',async()=>{
 const users=[{id:1,role:'admin',name:'Birinci Yönetici',avatarVersion:12},{id:2,role:'admin',name:'İkinci Yönetici',avatarVersion:null},{id:3,role:'admin',companyId:7}];
 for(const user of users){
  assert.equal(portraitUrl(user),adminPortraitUrl);
  assert.equal(directoryPortraitUrl(user),adminPortraitUrl);
  assert.equal(userPortraitMarkup(user),'<img src="'+adminPortraitUrl+'" alt="">');
 }
 const master=await fs.readFile(new URL('../assets/branding/approved-profile-20261103.png',import.meta.url));
 const served=await fs.readFile(new URL('../public'+adminPortraitUrl,import.meta.url));
 assert.equal(createHash('sha256').update(served).digest('hex'),createHash('sha256').update(master).digest('hex'));
});

test('Müşteri logo adresi hesap ve mağaza sürümünü korur; logosuz hesap kişisel resme dönmez',()=>{
 const user={id:21,role:'client',name:'Mağaza Kullanıcısı',companyId:2,avatarVersion:7};
 assert.equal(portraitUrl(user),'/api/account/portraits/21?v=7');
 assert.match(userPortraitMarkup(user),/src="\/api\/account\/portraits\/21\?v=7"/);
 assert.notEqual(portraitUrl(user),portraitUrl({...user,id:22}));
 assert.notEqual(portraitUrl(user),portraitUrl({...user,avatarVersion:8}));
 assert.equal(userPortraitMarkup({...user,avatarVersion:null},{fallbackName:'Terzioğlu Shop'}),'TS');
 assert.equal(userPortraitMarkup({...user,avatarVersion:null},{fallbackName:'<script>'}),'&lt;');
 assert.equal(userPortraitMarkup({...user,avatarVersion:7},{alt:'" onerror="alert(1)'}),'<img src="/api/account/portraits/21?v=7" alt="&quot; onerror=&quot;alert(1)">');
});

test('Kullanıcı yönetimi eski kişisel portreyi göstermez; müşteri logo ve hesap alanları korunur',()=>{
 const admin={id:1,name:'Yönetici',role:'admin',email:'a@example.test',avatarVersion:9,active:true};
 const client={id:2,name:'Mağaza Kullanıcısı',role:'client',email:'c@example.test',companyId:8,avatarVersion:4,active:true};
 const companies=[{id:8,name:'Terzioğlu Shop'}];
 const rows=accountDirectoryRows({items:[admin,client],companies});
 assert.match(rows,/omnigo-profile-v1\.png/);
 assert.doesNotMatch(rows,/\/api\/account\/portraits\/1\?/);
 assert.match(rows,/\/api\/account\/portraits\/2\?v=4&amp;fit=1/);
 for(const user of [admin,client]){
  const portrait=accountDirectoryPortrait(user);
  assert.doesNotMatch(portrait,/<form|type="file"|data-directory-remove-portrait|data-directory-portrait/);
  const form=accountDirectoryForm(user,companies);
  for(const name of ['name','email','username','role','companyId','active','password'])assert.ok(form.includes('name="'+name+'"'));
 }
 assert.match(accountDirectoryPortrait(admin),/ortak Omnigo profil logosu otomatik kullanılır/);
 assert.match(accountDirectoryPortrait(client),/bağlı mağazanın logosundan otomatik alınır/);
 assert.doesNotMatch(accountDirectoryPortrait({...client,avatarVersion:null}),/<img/);
});

test('Müşteri logosu canlı yenilenir ve kaldırılırken yönetici resmi mağazaya dönüşmez',()=>{
 const company={id:8,name:'İlk Mağaza',hasLogo:true,brandingVersion:1};
 const user={id:2,role:'client',companyId:8,name:'Kullanıcı',avatarVersion:1};
 const avatar={innerHTML:userPortraitMarkup(user)};
 const root={querySelectorAll:selector=>selector==='.account .avatar'?[avatar]:[]};
 const state={user,company:8,companies:[company]};
 assert.equal(applyCustomerBranding(state,root,{id:8,name:'Yeni Mağaza',hasLogo:true,brandingVersion:2}),true);
 assert.equal(avatar.innerHTML,'<img src="/api/account/portraits/2?v=2" alt="">');
 assert.equal(applyCustomerBranding(state,root,{id:8,name:'Yeni Mağaza',hasLogo:false,brandingVersion:3}),true);
 assert.equal(avatar.innerHTML,'YM');
 state.user={id:1,role:'admin',avatarVersion:9};avatar.innerHTML=userPortraitMarkup(state.user);
 assert.equal(applyCustomerBranding(state,root,{id:8,name:'Başka İsim',hasLogo:true,brandingVersion:4}),true);
 assert.equal(avatar.innerHTML,'<img src="'+adminPortraitUrl+'" alt="">');
 assert.equal(state.user.avatarVersion,9);
});

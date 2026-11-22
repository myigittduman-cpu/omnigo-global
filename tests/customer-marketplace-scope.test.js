import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {workspaceToken,sameWorkspace} from '../public/workspace-context.js';
import {mutationPrompt} from '../public/action-confirmation.js';

const source=name=>readFileSync(new URL('../public/'+name,import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replace(/^export \{.*\};\r?\n/gm,'').replaceAll('export ','');
const html=(parts,...values)=>typeof parts==='string'?parts:parts.reduce((text,part,i)=>text+part+(values[i]??''),'');
const state=()=>({user:{id:10,role:'admin'},company:1,contextRevision:0,companies:[{id:1,name:'Birinci'},{id:2,name:'İkinci'}]});
const control=(value='')=>({value,disabled:false,checked:false,addEventListener(type,handler){this[type]=handler;}});
const submitEvent={preventDefault(){}};

function fixture(){
 const current=state(),calls=[],dialogs=[],setup=[],messages=[];let selection,allow=true,fail=false,saved=0;
 const catalog={items:[{provider:'n11',name:'n11',storeName:'İkinci mağaza',selected:true,version:4}]};
 const api=async(path,method='GET',data)=>{calls.push({path,method,data});if(fail&&method==='POST')throw new Error('Kaydedilemedi');return structuredClone(catalog);};
 const makeCard=()=>{
  const input=control('İkinci mağaza'),action=control(),checkbox=control('n11'),configure=control(),button=control(),error={textContent:''};
  const nameForm={elements:{storeName:input},reportValidity:()=>true},actionForm={elements:{action},reportValidity:()=>true};
  return {dataset:{marketplace:'n11'},input,action,checkbox,configure,nameForm,actionForm,error,
   querySelector:key=>({'[name=action]':action,'[name=storeName]':input,'[role=alert]':error,'[data-marketplace-select]':checkbox,'[data-marketplace-action-form]':actionForm,'[data-marketplace-name]':nameForm,'[data-marketplace-configure]':configure})[key],
   querySelectorAll:key=>key==='button'?[button]:[],replaceWith(next){dialogs.at(-1).card=next;}};
 };
 const openDialog=(title,content,options)=>{
  const master=control(),action=control(),submit=control(),error={textContent:''},summary={textContent:''},listeners=[];
  const bulk={elements:{action},reportValidity:()=>Boolean(action.value),querySelector:key=>key==='[role=alert]'?error:submit};
  const dialog={title,content,options,bulk,master,action,submit,error,open:true,card:makeCard(),addEventListener:(type,fn)=>listeners.push(fn),close(){this.open=false;listeners.splice(0).forEach(fn=>fn());}};
  const body={innerHTML:'',querySelector:key=>({'[data-marketplace-all]':master,'[data-bulk-summary]':summary,'.marketplace-bulk-save':submit,'#marketplaceBulk':bulk})[key],querySelectorAll:key=>key==='[data-marketplace]'?[dialog.card]:key==='[data-marketplace-select]'?[dialog.card.checkbox]:key==='[data-marketplace-select]:checked'?(dialog.card.checkbox.checked?[dialog.card.checkbox]:[]):[]};
  dialog.querySelector=key=>key==='.dialog-body'?body:null;dialog.body=body;dialogs.push(dialog);return dialog;
 };
 const deps={fitMarketplacePages:()=>()=>{},showFeedback:message=>messages.push(message),openMarketEditor:async options=>setup.push(options),preserveDialogView:dialog=>()=>{dialog.open=true;return true;},protectManagementDialog:d=>{d.dispatchEvent=()=>true;},closeDialogThen:async(d,next)=>{d.close?.();await next?.();},ensureMarketplaceAccess:async()=>true,finishAccessCover(){},i18nT:x=>x,i18nText:html,i18nHtml:html,api,esc:x=>x,openDialog,platform:()=>'',toast:x=>messages.push(x),workspaceToken,sameWorkspace,customerSetup:async(s,save,options)=>setup.push(options),canLeaveDialogDrafts:async()=>allow,marketplaceSettingsView:()=>'',marketplaceCard:()=>'',managementCompanySelector:()=>'<select></select>',bindManagementCompany:(d,c,id,options)=>selection={id,...options},document:{createElement:()=>({content:{querySelector:()=>makeCard()}})}};
 const run=Function(...Object.keys(deps),source('customer-marketplaces.js')+';return customerMarketplaces;')(...Object.values(deps));
 return {current,calls,dialogs,setup,messages,run:id=>run(current,async()=>saved++,id),get selection(){return selection;},get saved(){return saved;},setAllow:value=>allow=value,setFail:value=>fail=value};
}

test('Pazaryeri ayarları seçim yapılmadan müşteri verisi yüklemez',async()=>{
 const f=fixture();await f.run();assert.equal(f.calls.length,0);assert.equal(f.selection.id,null);
 await f.selection.onSelect(2);assert.equal(f.calls[0].path,'/management/marketplaces?company=2');assert.match(f.dialogs.at(-1).title,/İkinci/);assert.equal(f.current.company,1);
});
test('Mağaza adı yalnız Kaydet sonrası seçilen müşteriye gider',async()=>{
 const f=fixture();await f.run(2);const d=f.dialogs.at(-1);d.card.input.value='Yeni mağaza';assert.equal(f.calls.length,1);
 await d.card.nameForm.onsubmit(submitEvent);assert.equal(f.calls[1].path,'/management/marketplaces/n11?company=2');assert.deepEqual(f.calls[1].data,{action:'edit',version:4,storeName:'Yeni mağaza'});assert.equal(f.current.company,1);
});
test('Toplu seçim kaydetmeden uygulanmaz, kaydet seçilen müşteriye ve mağazalara gider',async()=>{
 const f=fixture();await f.run(2);const d=f.dialogs.at(-1);d.card.checkbox.checked=true;d.card.checkbox.onchange();assert.equal(d.submit.disabled,true);
 d.action.value='hide';d.action.change();assert.equal(d.submit.disabled,false);assert.equal(f.calls.length,1);
 await d.bulk.onsubmit(submitEvent);assert.equal(f.calls[1].path,'/management/marketplaces-bulk?company=2');assert.deepEqual(f.calls[1].data.items,[{provider:'n11',version:4}]);assert.equal(f.current.company,1);
 assert.ok(mutationPrompt(f.calls[1].path,'POST',f.calls[1].data));
});
test('Tek mağaza görünürlüğü de pencerenin müşterisini kullanır',async()=>{
 const f=fixture();await f.run(2);const card=f.dialogs.at(-1).card;card.action.value='hide';await card.actionForm.onsubmit(submitEvent);assert.equal(f.calls[1].path,'/management/marketplaces/n11?company=2');assert.equal(f.calls[1].data.action,'hide');
});
test('Başarısız kayıtta pencere, yazılan değer ve müşteri korunur',async()=>{
 const f=fixture();await f.run(2);f.setFail(true);const d=f.dialogs.at(-1);d.card.input.value='Kaybolmasın';assert.equal(await d.card.nameForm.onsubmit(submitEvent),false);assert.equal(d.card.input.value,'Kaybolmasın');assert.equal(f.messages.at(-1),'Kaydedilemedi');assert.equal(d.open,true);assert.equal(f.saved,0);
});
test('Kaydedilmemiş kart bilgisi varken toplu işlem gönderilmez',async()=>{
 const f=fixture();await f.run(2);const d=f.dialogs.at(-1);d.card.input.value='Taslak';d.card.checkbox.checked=true;d.action.value='hide';assert.equal(await d.bulk.onsubmit(submitEvent),false);assert.equal(f.calls.length,1);
});
test('API ekranı ve geri dönüş seçilen müşteriyi korur; taslakta iptal geçişi engeller',async()=>{
 const f=fixture();await f.run(2);f.setAllow(false);await f.dialogs.at(-1).card.configure.click();assert.equal(f.setup.length,0);
 f.setAllow(true);await f.dialogs.at(-1).card.configure.click();assert.equal(f.setup[0].company.id,2);assert.equal(f.setup[0].item.provider,'n11');await f.setup[0].refresh();assert.equal(f.selection.id,2);assert.equal(f.current.company,1);
});
test('Müşteri hesabı, geçersiz müşteri veya değişmiş çalışma alanı işlem yapamaz',async()=>{
 const f=fixture();f.current.user.role='client';await f.run(2);assert.equal(f.calls.length,0);f.current.user.role='admin';await f.run(999);assert.equal(f.calls.length,0);
 await f.run(2);f.current.contextRevision++;await f.dialogs.at(-1).card.nameForm.onsubmit(submitEvent);assert.equal(f.calls.length,1);
});

test('API kurulumundaki yükleme, bağlantı ve son kontrol aynı müşteriyi kullanır',async()=>{
 const current=state(),calls=[],mounts=[],fields={};let passwordCompany,back;
 const field=key=>fields[key]??=(Object.assign(control(),{dataset:{},append(){},querySelector:()=>null,querySelectorAll:()=>[],setAttribute(){},removeAttribute(){},focus(){},reset(){}}));
 const layout={...field('.setup-layout'),isConnected:true,querySelector:field};
 const dialog={open:true,contains:()=>true,querySelector:key=>key==='.setup-layout'?layout:field(key),querySelectorAll:()=>[],addEventListener(){}};
 const deps={preserveDialogView:dialog=>()=>{dialog.open=true;return true;},protectManagementDialog:d=>{d.dispatchEvent=()=>true;},closeDialogThen:async(d,next)=>{d.close?.();await next?.();},ensureMarketplaceAccess:async()=>true,finishAccessCover(){},bindShippingScope:()=>({value:()=>({mode:'regional',regionalCities:['Ankara']})}),bindInitialPassword:(form,scope)=>({refresh:async()=>passwordCompany=scope(),value:()=>({})}),addDialogBack:(d,cb)=>back=cb,customerPermissions(){},i18nT:x=>x,i18nText:html,i18nHtml:html,api:async(path)=>{calls.push(path);return {profile:{},users:[],items:[{provider:'n11',selected:true}]};},upload(){},openDialog:()=>dialog,toast:message=>{throw new Error(message);},workspaceToken,sameWorkspace,setupView:()=>'',setupSteps:new Array(6),accountsView:()=>'',reviewView:()=>'',mountSetupConnections:async(root,options)=>mounts.push(options),canLeaveDialogDrafts:async()=>true,document:{createElement:()=>field('permissionButton')}};
 Object.assign(deps,{
  FormData:class {[Symbol.iterator](){return [][Symbol.iterator]();}},
  window:{addEventListener(){},removeEventListener(){}},
  paintContent(target,markup){target.innerHTML=markup;},
 });
 const run=Function(...Object.keys(deps),source('customer-setup.js')+';return customerSetup;')(...Object.values(deps));
 await run(current,()=>{},{provider:'n11',companyId:2,onBack:()=>{}});
 assert.equal(passwordCompany,undefined);assert.equal(mounts[0].company.id,2);assert.equal(mounts[0].plan,null);assert.equal(typeof back,'function');
 await field('[data-defer-api]').onclick();assert.ok(calls.includes('/admin/connection-health?company=2'));assert.ok(calls.every(path=>path.endsWith('company=2')));assert.equal(current.company,1);
});

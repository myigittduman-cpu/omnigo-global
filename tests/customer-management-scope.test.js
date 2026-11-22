import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {workspaceToken,sameWorkspace} from '../public/workspace-context.js';

const source=name=>readFileSync(new URL('../public/'+name,import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replaceAll('export ','');
const html=(parts,...values)=>typeof parts==='string'?parts:parts.reduce((text,part,i)=>text+part+(values[i]??''),'');
const state=()=>({user:{id:10,role:'admin'},company:1,contextRevision:0,companies:[{id:1,name:'Birinci'},{id:2,name:'İkinci'}]});
const form=(values={})=>{const submit={disabled:false},error={textContent:''};return {values,elements:{logo:{files:[{size:20}]}},querySelector:key=>key.includes('submit')?submit:error};};
class FormValues{constructor(form){this.values=form.values;}*[Symbol.iterator](){yield*Object.entries(this.values);}}

function managementFixture(){
 const current=state(),calls=[],dialogs=[],accountOptions=[];let selection,fail=false,saved=0;
 const api=async(path,method='GET',data)=>{calls.push({path,method,data});if(fail&&method==='PUT')throw new Error('Kayıt başarısız');return {profile:{version:'v2',contactName:'İkinci kişi'},users:[{id:22,name:'İkinci kullanıcı'}],mail:[],mailReady:true};};
 const openDialog=(title,content,options)=>{
  const tabs=['profile','accounts','mail','shipping'].map(tab=>({dataset:{tab},getAttribute:()=>tab==='profile'?'true':'false',setAttribute(){},click(){return this.onclick?.();}}));
  const fields={'#customerShipping':form(),'#customerProfile':form({name:'İkinci güncel'}),'#customerLogo':form(),'[data-add-customer-account]':{}},edit={dataset:{editAccount:'22'}};
  const accountSection={innerHTML:'',querySelector:key=>fields[key],querySelectorAll:key=>key==='[data-edit-account]'?[edit]:[]};fields['[data-section="accounts"]']=accountSection;
  const listeners=[];
  const dialog={title,content,options,fields,tabs,edit,open:true,contains:()=>true,addEventListener:(type,callback)=>listeners.push(callback),close(){this.open=false;listeners.splice(0).forEach(f=>f());},querySelector:key=>fields[key]||tabs.find(b=>key===`[data-tab="${b.dataset.tab}"]`),querySelectorAll:key=>key==='[data-tab]'?tabs:key==='[data-edit-account]'?[edit]:[]};
  dialogs.push(dialog);return dialog;
 };
 const dependencies={finishAccessCover(){},preserveDialogView:()=>Object.assign(()=>true,{restoresDialog:true}),ensureMarketplaceAccess:async()=>true,protectManagementDialog:d=>{d.dispatchEvent=()=>true;},shippingScopeFields:()=>'',bindShippingScope:()=>({value:()=>({mode:"regional",regionalCities:["Ankara"]})}),customerAccountList:()=>'',customerAccountEditor:(s,u,o)=>accountOptions.push({type:'edit',user:u,...o}),closeDialogThen:async(d,next)=>{d.close();await next();},i18nT:x=>x,i18nText:html,i18nHtml:html,currentLocale:()=> 'tr-TR',api,upload:async path=>calls.push({path,method:'UPLOAD'}),esc:x=>x??'',openDialog,toast:()=>{},workspaceToken,sameWorkspace,accountForm:(s,done,o)=>accountOptions.push({type:'create',...o,onSaved:done}),canLeaveDialogDrafts:async()=>true,managementCompanySelector:()=>'<select></select>',bindManagementCompany:(d,c,id,options)=>selection={id,...options},FormData:FormValues};
 Object.assign(dependencies,{newRecordsPanel:()=>'',bindNewRecordsPanel:()=>async()=>{},bindCustomerMenuLabelEditor:()=>async()=>{}});
 const run=Function(...Object.keys(dependencies),source('customer-management.js')+';return customerManagement;')(...Object.values(dependencies));
 return {current,calls,dialogs,accountOptions,get selection(){return selection;},run:(tab='profile',companyId=null)=>run(current,async()=>saved++,tab,companyId),fail:()=>fail=true,get saved(){return saved;}};
}

test('Açılış müşteri seçimi bekler; ana çalışma alanının bilgileri otomatik yüklenmez',async()=>{
 const f=managementFixture();await f.run();assert.equal(f.calls.length,0);assert.equal(f.selection.id,null);
 await f.selection.onSelect(2);assert.equal(f.calls[0].path,'/management/customer?company=2');assert.match(f.dialogs.at(-1).title,/İkinci/);assert.equal(f.current.company,1);
});
test('Firma kaydı yalnız seçilen müşteriye gider; yenilenen pencere aynı müşteride kalır',async()=>{
 const f=managementFixture();await f.run('profile',2);await f.dialogs.at(-1).fields['#customerProfile'].onsubmit({preventDefault(){}});
 assert.equal(f.calls[1].path,'/management/customer?company=2');assert.equal(f.calls[1].method,'PUT');assert.equal(f.calls[1].data.version,'v2');assert.equal(f.calls[2].path,'/management/customer?company=2');assert.equal(f.selection.id,2);assert.equal(f.current.company,1);assert.equal(f.saved,1);
});
test('Başarısız kayıtta form ve müşteri korunur, başarılı kaydetmiş sayılmaz',async()=>{
 const f=managementFixture();await f.run('profile',2);f.fail();const d=f.dialogs.at(-1);await d.fields['#customerProfile'].onsubmit({preventDefault(){}});assert.equal(d.open,true);assert.equal(f.saved,0);assert.equal(d.fields['#customerProfile'].querySelector('.form-error').textContent,'Kayıt başarısız');
});
test('Eski hesap sekmesi firma ayarlarında kullanıcı yönetimini açmaz',async()=>{
 const f=managementFixture();await f.run('accounts',2);
 const dialog=f.dialogs.at(-1);
 assert.match(dialog.content,/data-tab="profile"/);
 assert.doesNotMatch(dialog.content,/data-tab="accounts"|data-add-customer-account|data-edit-account/);
 assert.deepEqual(f.accountOptions,[]);assert.equal(f.selection.id,2);
 assert.equal(f.current.company,1);assert.ok(f.calls.every(call=>call.method==='GET'));
});
test('Logo yüklemesi de seçilen müşteri ile sınırlıdır',async()=>{
 const f=managementFixture();await f.run('profile',2);await f.dialogs.at(-1).fields['#customerLogo'].onsubmit({preventDefault(){}});assert.equal(f.calls[1].path,'/management/logo?company=2');assert.equal(f.calls[1].method,'UPLOAD');assert.equal(f.current.company,1);
});
test('Müşteri hesabı ve geçersiz müşteri seçimi yönetim verisi açamaz',async()=>{
 const f=managementFixture();f.current.user.role='client';await f.run('profile',2);assert.equal(f.calls.length,0);f.current.user.role='admin';await f.run('profile',999);assert.equal(f.calls.length,0);
});

function selectorFixture(){
 const companies=state().companies,select={value:'1',isConnected:true},chosen=[],prompts=[];let allow=false,current=true,closed=0;
 const deps={html,esc:x=>x,requestCustomerSwitch:()=>null,canLeaveDialogDrafts:async o=>{prompts.push(o);return allow;},closeDialogThen:async(d,next)=>{closed++;await next();}};
 const bind=Function(...Object.keys(deps),source('customer-management-selector.js')+';return bindManagementCompany;')(...Object.values(deps));
 bind({querySelector:()=>select},companies,1,{current:()=>current,onSelect:id=>chosen.push(id)});
 return {select,chosen,prompts,setAllow:value=>allow=value,setCurrent:value=>current=value,get closed(){return closed;},change:async value=>{select.value=value;await select.onchange();}};
}
test('Kaydedilmemiş değişiklikte Vazgeç seçilen müşteriyi ve formu korur',async()=>{
 const f=selectorFixture();await f.change('2');assert.equal(f.select.value,'1');assert.deepEqual(f.chosen,[]);assert.equal(f.closed,0);assert.deepEqual(f.prompts[0],{source:'Birinci',target:'İkinci'});assert.equal(f.select.disabled,false);
});
test('Kaydet veya kaydetmeden devam onayı sonrası yalnız hedef müşteri açılır',async()=>{
 const f=selectorFixture();f.setAllow(true);await f.change('2');assert.deepEqual(f.chosen,[2]);assert.equal(f.closed,0);
});
test('Eski çalışma alanı veya geçersiz seçim müşteriyi değiştiremez',async()=>{
 const f=selectorFixture();f.setAllow(true);await f.change('999');f.setCurrent(false);await f.change('2');assert.deepEqual(f.chosen,[]);assert.equal(f.closed,0);
});

test('Hesap düzenleyicisinin varsayılan mağazası ve şifre uç noktası pencere müşterisidir',async()=>{
 const s=state(),requests=[];let rendered='';const details=form({companyId:'2',name:'İkinci',active:'true'}),password=form();
 details.elements={};details.reportValidity=()=>true;password.elements={password:{value:'test-only-password'},confirm:{value:'test-only-password'}};password.reportValidity=()=>true;
 password.reset=()=>{};password.querySelectorAll=()=>[];
 const listeners=[],bound=[],dialog={querySelector:key=>key==='[data-account-details]'?details:password,dispatchEvent(){},addEventListener:(e,cb)=>listeners.push(cb),close:()=>listeners.splice(0).forEach(cb=>cb())};
 const deps={api:async(path,method)=>requests.push({path,method}),html,t:x=>x,esc:x=>x,openDialog:(title,content)=>{rendered=content;return dialog;},toast(){},bindAccountPassword:input=>bound.push(input),addDialogBack(){},workspaceToken,sameWorkspace,FormData:FormValues};
 const edit=Function(...Object.keys(deps),source('customer-account-editor.js')+';return customerAccountEditor;')(...Object.values(deps));
 edit(s,{id:22,name:'İkinci',active:1},{companyId:2,onSaved:async()=>{},onBack(){}});
 assert.deepEqual(bound,[password.elements.password,password.elements.confirm]);assert.match(rendered,/<option value="2" selected>/);assert.equal(await password.onsubmit({preventDefault(){}}),true);assert.deepEqual(requests,[{path:'/management/users/22/password?company=2',method:'POST'}]);assert.equal(s.company,1);
});

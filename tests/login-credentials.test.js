import test from 'node:test';
import assert from 'node:assert/strict';
import {attachRememberedCredentials} from '../public/login-credentials.js';
import {attachAdminLogin} from '../public/admin-login.js';

const key='omnigo.remembered-password.v1';
function fixture({checked=true,saved=true}={}){
 const values=new Map(saved?[[key,JSON.stringify({identity:'admin@example.test'})]]:[]),events=new Map();
 const field=(name,value)=>({value,type:'text',checked,disabled:false,required:true,readOnly:false,label:{hidden:false},closest(){return this.label;},focus(){},addEventListener(event,callback){events.set(name+':'+event,callback);},parentElement:{querySelector:()=>null}});
 const form={isConnected:true,dataset:{},elements:{email:field('email','admin@example.test'),password:field('password',''),rememberEmail:field('remember','')}};
 const storage={getItem:k=>values.get(k),setItem:(k,v)=>values.set(k,v),removeItem:k=>values.delete(k)};
 const requests=[],stored=[];const credentials={async get(options){requests.push(options);return {type:'password',id:'admin@example.test',password:'Only-In-The-Vault!'};},async store(value){stored.push(value);},async preventSilentAccess(){}};
 class PasswordCredential{constructor(value){Object.assign(this,value);}}
 return {form,storage,credentials,PasswordCredential,requests,stored,values,events};
}

test('Güvenli kasadan dolum yalnız seçili tercih ve aynı kimlikte yapılır; oturum açılmaz',async()=>{
 const f=fixture(),control=attachRememberedCredentials(f.form,f);assert.equal(await control.ready,true);
 assert.equal(f.form.elements.password.value,'Only-In-The-Vault!');assert.equal(f.form.elements.password.type,'password');
 assert.deepEqual(f.requests,[{password:true,mediation:'silent'}]);assert.equal(f.stored.length,0);
 assert.ok(!JSON.stringify([...f.values]).includes('Only-In-The-Vault!'));
});
test('Farklı hesaba ait kasadaki parola veya kullanıcı yazmaya başladıktan sonraki geç yanıt kullanılmaz',async()=>{
 const wrong=fixture();wrong.credentials.get=async()=>({type:'password',id:'another@example.test',password:'Wrong-Account!'});
 assert.equal(await attachRememberedCredentials(wrong.form,wrong).ready,false);assert.equal(wrong.form.elements.password.value,'');
 const late=fixture();let resolve;late.credentials.get=()=>new Promise(done=>resolve=done);const control=attachRememberedCredentials(late.form,late);
 late.form.elements.password.value='User-Is-Typing!';late.events.get('password:input')();resolve({type:'password',id:'admin@example.test',password:'Old!'});
 assert.equal(await control.ready,false);assert.equal(late.form.elements.password.value,'User-Is-Typing!');
});
test('Başarılı girişin kayıt adımı parolayı yalnız kasa API’sine verir; kalıcı yerel kayıtta parola yoktur',async()=>{
 const f=fixture({saved:false}),control=attachRememberedCredentials(f.form,f);await control.ready;f.form.elements.password.value='New-Strong-Password!';
 assert.equal(await control.rememberCredentials({name:'Örnek Yönetici'}),true);
 assert.equal(f.stored[0].id,'admin@example.test');assert.equal(f.stored[0].password,'New-Strong-Password!');
 assert.ok(!JSON.stringify([...f.values]).includes('New-Strong-Password!'));
 f.form.elements.rememberEmail.checked=false;f.events.get('remember:change')();assert.equal(f.values.has(key),false);
 assert.equal(await control.rememberCredentials(),false);assert.equal(f.stored.length,1);
});
test('Tercih kapalıyken kasaya erişilmez; destek veya kasa hatası giriş formunu kilitlemez',async()=>{
 const off=fixture({checked:false});assert.equal(await attachRememberedCredentials(off.form,off).ready,false);assert.equal(off.requests.length,0);
 const missing=fixture();missing.PasswordCredential=null;const fallback=attachRememberedCredentials(missing.form,missing);assert.equal(await fallback.ready,false);assert.equal(missing.form.dataset.credentialStorage,'autofill');
 const denied=fixture();denied.credentials.get=async()=>{throw new Error('İzin verilmedi.');};assert.equal(await attachRememberedCredentials(denied.form,denied).ready,false);
 assert.equal(denied.form.elements.password.value,'');
});
test('Geçerli yönetici oturumu şifreyi gizlemez ve giriş kendiliğinden devam etmez',async()=>{
 const f=fixture();f.form.elements.password.label.hidden=true;f.form.elements.password.disabled=true;
 const login=attachAdminLogin(f.form,{...f,sessionUser:{id:3,role:'admin'}});await login.ready;
 assert.equal(f.form.elements.password.disabled,false);assert.equal(f.form.elements.password.required,true);
 assert.equal(f.form.elements.password.label.hidden,false);assert.equal(login.resumeAccount(),null);
});
test('Beni Hatırla kapatılarak giriş yapıldıktan sonra otomatik parola geri yüklemesi temizlenir; elle giriş korunur',async()=>{
 const f=fixture({checked:false});f.values.set('omnigo.password-autofill-disabled.v1','true');f.form.elements.password.value='Restored-By-Browser!';
 const control=attachRememberedCredentials(f.form,f);await control.ready;assert.equal(f.form.elements.password.value,'');assert.equal(f.form.elements.password.type,'password');
 f.form.elements.password.value='Late-Autofill!';f.events.get('password:animationstart')({animationName:'omnigo-login-autofill'});assert.equal(f.form.elements.password.value,'');
 f.events.get('password:pointerdown')();f.form.elements.password.value='Deliberate-Entry!';f.events.get('password:animationstart')({animationName:'omnigo-login-autofill'});assert.equal(f.form.elements.password.value,'Deliberate-Entry!');
 await control.rememberCredentials();assert.equal(f.stored.length,0);assert.ok(!JSON.stringify([...f.values]).includes('Deliberate-Entry!'));
});
test('Windows kasası tarayıcı kasa desteği olmadan maskeli doldurur; açık parola web depolamasına yazılmaz',async()=>{
 const f=fixture(),calls=[];f.PasswordCredential=null;f.native={async get(id){calls.push(['get',id]);return {type:'password',id,password:'Native-Test-Secret!'};},async store(value){calls.push(['store',value.id]);},async clear(){calls.push(['clear']);}};
 const control=attachRememberedCredentials(f.form,f);assert.equal(await control.ready,true);assert.equal(f.form.dataset.credentialStorage,'device-vault');assert.equal(f.form.elements.password.type,'password');assert.equal(f.form.elements.password.value,'Native-Test-Secret!');assert.equal(f.requests.length,0);
 await control.rememberCredentials();assert.ok(!JSON.stringify([...f.values]).includes('Native-Test-Secret!'));f.form.elements.rememberEmail.checked=false;f.events.get('remember:change')();await control.rememberCredentials();assert.ok(calls.some(call=>call[0]==='clear'));
});

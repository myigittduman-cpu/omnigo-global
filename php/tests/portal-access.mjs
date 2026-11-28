import {request as httpRequest} from 'node:http';
import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,readFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {resolve,join} from 'node:path';
import {createServer} from 'node:net';
import {once} from 'node:events';
const root=resolve(import.meta.dirname,'../..');
const php=process.env.PHP_BINARY;
if(!php)throw new Error('PHP_BINARY belirtilmeli.');
const phpLiteral=value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";


test('Ayrı adresler: roller, oturum taşıma, kaynak kontrolü ve eski oturum reddi',async()=>{
 const dir=mkdtempSync(join(tmpdir(),'omnigo-host-test-'));
 const probe=createServer();probe.listen(0,'127.0.0.1');await once(probe,'listening');const port=probe.address().port;await new Promise(r=>probe.close(r));
 const base=`http://127.0.0.1:${port}`,config=join(dir,'config.php'),db=join(dir,'test.sqlite');
 writeFileSync(config,`<?php return ['origin'=>'http://admin.test','portals'=>['admin'=>'http://admin.test','client'=>'http://client.test'],'database'=>['dsn'=>${phpLiteral('sqlite:'+db)}],'setup_token_hash'=>hash('sha256','test-setup-code')];`);
 const init=join(dir,'init.php');
 writeFileSync(init,`<?php require ${phpLiteral(join(root,'php/bootstrap.php'))}; $db=new \\Omnigo\\Database((require ${phpLiteral(config)})['database']); $db->pdo->exec(file_get_contents(${phpLiteral(join(root,'php/tests/schema.sqlite.sql'))})); \\Omnigo\\InventorySchema::install($db); \\Omnigo\\SupportSchema::migrate($db); \\Omnigo\\NotificationSchema::migrate($db); \\Omnigo\\DeliverySchema::install($db); \\Omnigo\\ShipmentSchema::install($db); \\Omnigo\\InventorySchema::install($db); \\Omnigo\\ClientSessionOperator::install($db); \\Omnigo\\AccountSettingsSchema::install($db); \\Omnigo\\AdminMfaSchema::install($db); \\Omnigo\\OperationSchema::install($db); \\Omnigo\\MarketplaceGate::install($db);`);
 const result=spawnSync(php,[init],{encoding:'utf8'});assert.equal(result.status,0,result.stderr);
 let logs='';
 const startServer=()=>{
  const child=spawn(php,['-S',`127.0.0.1:${port}`,'-t',join(root,'deploy/portal/public'),join(root,'php/tests/router.php')],{env:{...process.env,OMNIGO_CONFIG:config},stdio:['ignore','pipe','pipe']});
  child.stderr.on('data',x=>logs+=x); return child;
 };
 let server=startServer();
 const request=async(host,path,method='GET',data,auth={})=>{
  const r=await new Promise((resolve,reject)=>{const req=httpRequest(base+'/api'+path,{method,headers:{Host:host,...(auth.grant?{'X-Omnigo-Management-Grant':auth.grant}:{}),'Content-Type':'application/json',...(auth.cookie?{Cookie:auth.cookie}:{}),...(auth.csrf?{'X-CSRF-Token':auth.csrf}:{}),...(auth.origin?{Origin:auth.origin}:{})}},res=>{let body='';res.on('data',chunk=>body+=chunk);res.on('end',()=>resolve({status:res.statusCode,json:async()=>JSON.parse(body),headers:{get:name=>{const value=res.headers[name];return Array.isArray(value)?value[0]:value??null;}}}));});req.on('error',reject);if(data)req.write(JSON.stringify(data));req.end();});
  return {status:r.status,data:await r.json(),cookie:r.headers.get('set-cookie')?.split(';')[0],header:r.headers};
 };
 try{
  for(let i=0;i<30;i++){try{await request('admin.test','/status');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  const account={email:'admin@example.test',name:'Yönetici',password:'Strong-Test-1234',setupCode:'test-setup-code'};
  assert.equal((await request('client.test','/status')).data.needsSetup,false);
  assert.equal((await request('client.test','/setup','POST',account)).status,403);
  assert.equal((await request('admin.test','/setup','POST',account)).status,201);
  assert.equal((await request('other.test','/status')).status,403);
  assert.equal((await request('admin.test','/status')).data.portalRole,'admin');
  assert.equal((await request('client.test','/status')).data.portalRole,'client');
  const login=await request('admin.test','/login','POST',account);assert.equal(login.status,200,logs);assert.match(login.cookie,/^og_admin_session=/);
  assert.match(login.header.get('set-cookie'),/HttpOnly/i);assert.match(login.header.get('set-cookie'),/SameSite=Strict/i);assert.doesNotMatch(login.header.get('set-cookie'),/Domain=/i);
  assert.doesNotMatch(login.header.get('set-cookie'),/Expires=|Max-Age=/i);
  const admin={cookie:login.cookie,csrf:login.data.csrf};
  const gate=await request('admin.test','/admin/marketplace-access?company=1','POST',{setup:true,password:'Local-Fixture-Gate-1234',confirmation:'Local-Fixture-Gate-1234'},admin);assert.equal(gate.status,200);admin.grant=gate.data.grant;
  const clientAccount={email:'client@example.test',name:'Müşteri',password:'Client-Test-1234',companyId:1,username:'testclient'};
  assert.equal((await request('admin.test','/users','POST',clientAccount,admin)).status,201);
  assert.equal((await request('client.test','/login','POST',account)).status,403);
  assert.equal((await request('admin.test','/login','POST',clientAccount)).status,403);
  const clientLogin=await request('client.test','/login','POST',clientAccount);assert.equal(clientLogin.status,200);assert.match(clientLogin.cookie,/^og_client_session=/);
  const client={cookie:clientLogin.cookie,csrf:clientLogin.data.csrf};
  assert.equal((await request('client.test','/me','GET',null,client)).data.user.role,'client');
  assert.equal((await request('admin.test','/me','GET',null,admin)).data.user.role,'admin');
  assert.equal((await request('client.test','/me','GET',null,{cookie:admin.cookie})).status,401);
  assert.equal((await request('client.test','/me','GET',null,{cookie:admin.cookie.replace('og_admin_session','og_client_session')})).status,401);
  assert.equal((await request('admin.test','/me','GET',null,{cookie:client.cookie.replace('og_client_session','og_admin_session')})).status,401);
  assert.equal((await request('client.test','/me','GET',null,{cookie:admin.cookie.replace('og_admin_session','og_portal_session')})).status,401);
  assert.equal((await request('client.test','/users','POST',clientAccount,client)).status,403);
  for(const provider of ['hepsiburada','n11','pazarama','koctas','pttavm','idefix','trendyol']){
   assert.equal((await request('client.test',`/integrations/${provider}/credentials?company=1`,'POST',{},client)).status,403,'API bilgileri müşteriye gönderilmemeli');
   assert.equal((await request('admin.test',`/integrations/${provider}/credentials?company=1`,'POST',{}, {cookie:admin.cookie})).status,403,'API görüntüleme CSRF gerektirir');
   assert.equal((await request('admin.test',`/integrations/${provider}/credentials?company=1`,'GET',null,admin)).status,405,'API bilgileri normal GET yanıtlarına eklenmemeli');
  }
  assert.equal((await request('admin.test','/logout','POST',{}, {...admin,origin:'http://client.test'})).status,403);
  assert.equal((await request('admin.test','/me','GET',null,admin)).status,200);
  for(const [host,credentials] of [['admin.test',account],['client.test',clientAccount]]) {
   const persistent=await request(host,'/login','POST',{...credentials,keepSession:true});
   assert.equal(persistent.status,200);
   const maxAge=Number(persistent.header.get('set-cookie').match(/Max-Age=(\d+)/i)?.[1]);
   assert.ok(maxAge>=34559990 && maxAge<=34560000);
   assert.match(persistent.header.get('set-cookie'),/HttpOnly/i);
   const session={cookie:persistent.cookie,csrf:persistent.data.csrf};
   assert.equal((await request(host,'/account/session','GET',null,session)).data.keepSession,true);
   assert.equal((await request(host,'/me','GET',null,session)).data.keepSession,true,'Kalıcı oturum açılış yanıtında belirtilmeli');
   assert.equal((await request(host,'/account/session','PUT',{keepSession:false},{cookie:session.cookie})).status,403,'Oturum tercihi CSRF koruması');
   assert.equal((await request(host,'/account/session','PUT',{keepSession:'false'},session)).status,400);
   assert.equal((await request(host,'/me','GET',null,session)).status,200);
   const stopped=once(server,'exit'); server.kill(); await stopped; server=startServer();
   for(let i=0;i<30;i++){try{await request(host,'/status');break;}catch{await new Promise(r=>setTimeout(r,100));}}
   assert.equal((await request(host,'/me','GET',null,session)).status,200,'Kalıcı oturum sunucu yeniden açıldığında korunmalı');
   const secondLogin=await request(host,'/login','POST',{...credentials,keepSession:true});
   const secondSession={cookie:secondLogin.cookie,csrf:secondLogin.data.csrf};
   const disabled=await request(host,'/account/session','PUT',{keepSession:false},secondSession);
   assert.equal(disabled.status,200);assert.equal(disabled.data.signedOut,true);
   assert.equal((await request(host,'/me','GET',null,secondSession)).status,401,'Seçenek kapatılınca aynı cihazdan çıkış yapılmalı');
   assert.equal((await request(host,'/me','GET',null,session)).status,200,'Diğer cihaz oturumu korunmalı');
   assert.equal((await request(host,'/logout','POST',{},session)).status,200);
   assert.equal((await request(host,'/me','GET',null,session)).status,401);
  }
  assert.equal((await request('admin.test','/login','POST',{...account,keepSession:'false'})).status,400);
 }finally{server.kill();await once(server,'exit');rmSync(dir,{recursive:true,force:true});}
});

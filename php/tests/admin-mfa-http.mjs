import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {createServer} from 'node:net';
import {once} from 'node:events';
const root=resolve(import.meta.dirname,'../..'),php=process.env.PHP_BINARY;
if(!php)throw new Error('PHP_BINARY belirtilmeli.');
const literal=value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";
test('HTTP: email factor has no session before code; settings require CSRF; old sessions are revoked',async()=>{
 const folder=mkdtempSync(join(tmpdir(),'omnigo-mfa-http-')),db=join(folder,'test.sqlite'),config=join(folder,'config.php');
 const portProbe=createServer().listen(0,'127.0.0.1');await once(portProbe,'listening');const port=portProbe.address().port;await new Promise(r=>portProbe.close(r));
 const base='http://127.0.0.1:'+port,password='Local-Test-Password-957!';
 writeFileSync(config,`<?php return ['origin'=>${literal(base)},'portal_role'=>'admin','admin_mfa'=>true,'database'=>['dsn'=>${literal('sqlite:'+db)}],'mail'=>['enabled'=>true,'from'=>'no-reply@example.test','transport'=>'mail','key'=>base64_encode(str_repeat('k',32))]];`);
 const run=code=>{const file=join(folder,'probe.php');writeFileSync(file,`<?php require ${literal(join(root,'php/bootstrap.php'))};$config=require ${literal(config)};$db=new \\Omnigo\\Database($config['database']);${code}`);const result=spawnSync(php,[file],{encoding:'utf8'});assert.equal(result.status,0,result.stderr);return result.stdout;};
 run(`$db->pdo->exec(file_get_contents(${literal(join(root,'php/tests/schema.sqlite.sql'))}));\\Omnigo\\AdminMfaSchema::install($db);$db->run("INSERT INTO users VALUES(1,'admin@example.test','Test',?,'admin',NULL)",[\\Omnigo\\Security::hashPassword(${literal(password)})]);`);
 const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',join(root,'public'),join(root,'php/tests/router.php')],{env:{...process.env,OMNIGO_CONFIG:config},stdio:'ignore'});
 const request=async(path,{data,cookie,csrf,origin}={})=>{const response=await fetch(base+'/api'+path,{method:data?'POST':'GET',headers:{'Content-Type':'application/json',...(cookie?{Cookie:cookie}:{}),...(csrf?{'X-CSRF-Token':csrf}:{}),...(origin?{Origin:origin}:{})},...(data?{body:JSON.stringify(data)}:{})});return {status:response.status,data:await response.json(),cookie:response.headers.get('set-cookie')?.split(';')[0]};};
 const code=()=>JSON.parse(run(`$job=$db->one("SELECT payload FROM mail_outbox WHERE state='pending' AND id LIKE 'mfa-%' ORDER BY created DESC LIMIT 1");$raw=base64_decode($job['payload']);$json=openssl_decrypt(substr($raw,28),'aes-256-gcm',str_repeat('k',32),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));echo json_encode(json_decode($json,true)['code']);`));
 try{
  let ready=false;for(let n=0;n<30;n++){try{await request('/status');ready=true;break;}catch{await new Promise(r=>setTimeout(r,100));}}assert.ok(ready);
  const first=await request('/login',{data:{email:'admin@example.test',password}});assert.equal(first.status,200);assert.ok(first.cookie);
  const auth={cookie:first.cookie,csrf:first.data.csrf};
  assert.equal((await request('/account/security/email/request',{cookie:first.cookie,data:{enabled:true,password}})).status,403);
  const pending=await request('/account/security/email/request',{...auth,data:{enabled:true,password}});assert.equal(pending.status,200);
  assert.equal((await request('/account/security',{...auth})).data.enabled,false);
  const enabled=await request('/account/security/email/verify',{...auth,data:{challenge:pending.data.challenge,code:code()}});assert.equal(enabled.status,200);assert.ok(enabled.cookie);assert.notEqual(enabled.cookie,first.cookie);
  assert.equal((await request('/me',{cookie:first.cookie})).status,401);
  assert.equal((await request('/me',{cookie:enabled.cookie})).status,200);
  const pendingLogin=await request('/login',{data:{email:'admin@example.test',password}});assert.equal(pendingLogin.status,200);assert.equal(pendingLogin.cookie,undefined);assert.equal(pendingLogin.data.mfaRequired,true);assert.equal(pendingLogin.data.csrf,undefined);
  assert.equal((await request('/me')).status,401);
  const otp=code();assert.equal((await request('/login/mfa/verify',{origin:'https://other.example.test',data:{challenge:pendingLogin.data.challenge,code:otp}})).status,403);
  const second=await request('/login/mfa/verify',{data:{challenge:pendingLogin.data.challenge,code:otp}});assert.equal(second.status,200);assert.ok(second.cookie);
  assert.equal((await request('/me',{cookie:second.cookie})).status,200);
  assert.equal((await request('/login/mfa/verify',{data:{challenge:pendingLogin.data.challenge,code:otp}})).status,401);
 }finally{server.kill();await once(server,'exit').catch(()=>{});rmSync(folder,{recursive:true,force:true,maxRetries:8,retryDelay:100});}
});

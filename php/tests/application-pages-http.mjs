import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,rmSync,realpathSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join,resolve,sep,basename} from 'node:path';
import {createServer} from 'node:net';
import {once} from 'node:events';
const root=resolve(import.meta.dirname,'../..'),php=process.env.PHP_BINARY;
if(!php)throw Error('PHP_BINARY belirtilmeli.');
const literal=value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";

test('HTTP: özel sayfa yönetimi yalnız yetkili hesapta; rol, mağaza, CSRF ve sürüm sınırları korunur',async()=>{
 const folder=mkdtempSync(join(tmpdir(),'omnigo-pages-http-'));
 const probe=createServer().listen(0,'127.0.0.1');await once(probe,'listening');const port=probe.address().port;await new Promise(r=>probe.close(r));
 const origin='http://127.0.0.1:'+port,password='Only-Synthetic-Page-Test-2026!',config=join(folder,'config.php');
 writeFileSync(config,`<?php return ['origin'=>${literal(origin)},'database'=>['dsn'=>${literal('sqlite:'+join(folder,'test.sqlite'))}]];`);
 const fixture=join(folder,'seed.php');
 writeFileSync(fixture,`<?php require ${literal(join(root,'php/bootstrap.php'))};require ${literal(join(root,'php/tests/support/http-fixture-schema.php'))};$config=require ${literal(config)};$db=new \\Omnigo\\Database($config['database']);$db->pdo->exec(file_get_contents(${literal(join(root,'php/tests/schema.sqlite.sql'))}));installHttpFixtureSchema($db);$db->run("INSERT INTO companies VALUES(1,'Örnek Bir'),(2,'Örnek İki')");$hash=\\Omnigo\\Security::hashPassword(${literal(password)});foreach([[1,'yigit.duman@omnigoglobal.com','Yiğit Duman','admin',null],[2,'other@example.test','Diğer Yönetici','admin',null],[3,'client@example.test','Örnek Müşteri','client',1],[4,'second@example.test','İkinci Müşteri','client',2]] as $user)$db->run('INSERT INTO users(id,email,name,password,role,company_id) VALUES(?,?,?,?,?,?)',[$user[0],$user[1],$user[2],$hash,$user[3],$user[4]]);`);
 const seeded=spawnSync(php,[fixture],{encoding:'utf8',windowsHide:true});assert.equal(seeded.status,0,seeded.stderr);
 const server=spawn(php,['-d','extension=fileinfo','-S',`127.0.0.1:${port}`,'-t',join(root,'public'),join(root,'php/tests/router.php')],{windowsHide:true,env:{...process.env,OMNIGO_CONFIG:config},stdio:'ignore'});
 const request=async(path,method='GET',data,auth={},extra={})=>{
  const response=await fetch(origin+'/api'+path,{method,headers:{'Content-Type':'application/json',...(auth.cookie?{Cookie:auth.cookie}:{}),...(auth.csrf?{'X-CSRF-Token':auth.csrf}:{}),...extra},...(data===undefined?{}:{body:JSON.stringify(data)})});
  return {status:response.status,data:await response.json(),cookie:response.headers.get('set-cookie')?.split(';')[0]};
 };
 try{
  let ready=false;for(let i=0;i<40;i++){try{await request('/status');ready=true;break;}catch{await new Promise(r=>setTimeout(r,100));}}assert.ok(ready);
  const login=async email=>{const r=await request('/login','POST',{email,password});assert.equal(r.status,200,JSON.stringify(r.data));return {cookie:r.cookie,csrf:r.data.csrf};};
  const owner=await login('yigit.duman@omnigoglobal.com'),other=await login('other@example.test'),client=await login('client@example.test'),second=await login('second@example.test');
  const route='/account/page-builder';
  assert.equal((await request(route)).status,401);
  assert.equal((await request(route,'GET',undefined,other)).status,403);
  assert.equal((await request(route,'GET',undefined,client)).status,403);
  const initial=await request(route,'GET',undefined,owner);assert.equal(initial.status,200);
  const page=(slug,role='both',scope='all',companyIds=[])=>({slug,title:'Örnek sayfa',content:'Örnek içerik',role,scope,companyIds,menuGroup:'operations'});
  const body={version:initial.data.draft.version,items:[page('ortak'),page('yonetici','admin'),page('magaza','client','selected',[1])]};
  assert.equal((await request(route,'PUT',body,{cookie:owner.cookie})).status,403);
  assert.equal((await request(route,'PUT',body,owner,{Origin:'https://other.example.test'})).status,403);
  const saved=await request(route,'PUT',body,owner);assert.equal(saved.status,200);
  assert.deepEqual((await request('/custom-pages?company=1','GET',undefined,client)).data.items,[]);
  assert.equal((await request(route+'/publish','POST',{version:1},owner)).status,409);
  const published=await request(route+'/publish','POST',{version:saved.data.version},owner);assert.equal(published.status,200);
  assert.deepEqual((await request('/custom-pages?company=1','GET',undefined,client)).data.items.map(p=>p.slug),['ortak','magaza']);
  assert.deepEqual((await request('/custom-pages?company=2','GET',undefined,second)).data.items.map(p=>p.slug),['ortak']);
  assert.equal((await request('/custom-pages?company=2','GET',undefined,client)).status,403);
  assert.equal((await request('/custom-pages?company=999','GET',undefined,owner)).status,404);
  assert.equal((await request('/custom-pages?company=-1','GET',undefined,owner)).status,400);
  assert.equal((await request(route+'/publish','POST',{version:saved.data.version},owner)).status,409);
  const rolled=await request(route+'/rollback','POST',{version:published.data.published.version},owner);assert.equal(rolled.status,200);assert.equal(rolled.data.published.items.length,0);
  assert.deepEqual((await request('/custom-pages?company=1','GET',undefined,client)).data.items,[]);
  const large={version:rolled.data.draft.version,items:[...['uzun-bir','uzun-iki','uzun-uc'].map(slug=>({...page(slug),content:'🛒'.repeat(8000)}))]};
  assert.ok(Buffer.byteLength(JSON.stringify(large))>65536);
  const largeSaved=await request(route,'PUT',large,owner);assert.equal(largeSaved.status,200,JSON.stringify(largeSaved.data));
  assert.equal(largeSaved.data.items[2].content,large.items[2].content);
 }finally{
  server.kill();await once(server,'exit').catch(()=>{});
  const actual=realpathSync(folder),base=realpathSync(tmpdir());
  if(!actual.startsWith(base+sep)||!basename(actual).startsWith('omnigo-pages-http-'))throw Error('Geçici test yolu doğrulanamadı.');
  rmSync(folder,{recursive:true,force:true,maxRetries:8,retryDelay:100});
 }
});

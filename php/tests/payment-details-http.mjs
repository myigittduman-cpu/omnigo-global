import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtemp,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve,join,sep} from 'node:path';
import {createServer} from 'node:net';
import {once} from 'node:events';

const root=resolve(import.meta.dirname,'../..'),php=process.env.PHP_BINARY;
const literal=value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";

test('PHP HTTP: detaylı hakediş JSON isteği kaydolur, düzenlenir ve geçersiz kalemler reddedilir',async()=>{
 assert.ok(php,'PHP_BINARY belirtilmeli.');
 const dir=await mkdtemp(join(tmpdir(),'omnigo-payment-http-'));
 const bootstrap=literal(join(root,'php/bootstrap.php')),schema=literal(join(root,'php/tests/schema.sqlite.sql'));
 const dsn=literal('sqlite:'+join(dir,'payments.sqlite'));
 const init=join(dir,'init.php'),router=join(dir,'router.php');
 await writeFile(init,`<?php require ${bootstrap}; $db=new \\Omnigo\\Database(['dsn'=>${dsn}]); $db->pdo->exec(file_get_contents(${schema})); \\Omnigo\\NotificationSchema::migrate($db); \\Omnigo\\PushSchema::install($db); (new \\Omnigo\\Repository($db))->createCompany('Test müşterisi'); $db->run("INSERT INTO users VALUES(1,'admin@example.test','Test yöneticisi','unused','admin',NULL)");`);
 const initialized=spawnSync(php,[init],{encoding:'utf8'});assert.equal(initialized.status,0,initialized.stderr);
 // Loopback-only fixture uses the production request decoder, validator and repository.
 await writeFile(router,`<?php require ${bootstrap}; $db=new \\Omnigo\\Database(['dsn'=>${dsn}]); $repo=new \\Omnigo\\Repository($db); $user=['id'=>1,'role'=>'admin']; try { if($_SERVER['REQUEST_METHOD']==='GET') \\Omnigo\\Http::json($repo->records(1,'payments',$user)); $body=\\Omnigo\\Http::body(); \\Omnigo\\Http::json($repo->save($user,1,'payments',$body,isset($_GET['id'])?(int)$_GET['id']:null),isset($_GET['id'])?200:201); } catch(\\Omnigo\\HttpError $e) { \\Omnigo\\Http::json(['error'=>$e->getMessage()],$e->getCode()); }`);
 const probe=createServer();probe.listen(0,'127.0.0.1');await once(probe,'listening');const port=probe.address().port;await new Promise(r=>probe.close(r));
 const base=`http://127.0.0.1:${port}`,server=spawn(php,['-S',`127.0.0.1:${port}`,router],{stdio:['ignore','pipe','pipe']});
 let logs='';server.stderr.on('data',chunk=>logs+=chunk);
 const request=async(method='GET',body,id)=>{const response=await fetch(base+(id?'/?id='+id:'/'),{method,headers:{'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});return {status:response.status,data:await response.json()};};
 try{
  let ready=false;for(let i=0;i<30;i++){try{await request();ready=true;break;}catch{await new Promise(r=>setTimeout(r,100));}}assert.ok(ready,logs);
  const payload={channel:'Hepsiburada',expectedDate:'2026-11-02',expectedCents:123456,status:'pending',source:'Test girişi',periodStart:'2026-10-01',periodEnd:'2026-10-30',details:[
   {section:'sales',label:'Satış tutarı',amountCents:150000,rateBasisPoints:null},
   {section:'commission',label:'Komisyon',amountCents:26544,rateBasisPoints:1500},
   {section:'tax',label:'KDV',amountCents:0,rateBasisPoints:2000},
   {section:'adjustment',label:'Düzeltme',amountCents:-250,rateBasisPoints:null}
  ]};
  const added=await request('POST',payload);assert.equal(added.status,201,JSON.stringify(added));assert.deepEqual(added.data.details,payload.details);
  const edited=await request('PUT',{...payload,version:added.data.version,expectedCents:123206},added.data.id);assert.equal(edited.status,200,JSON.stringify(edited));assert.equal(edited.data.version,2);
  const rows=await request();assert.equal(rows.data.length,1);assert.equal(rows.data[0].expectedCents,123206);assert.deepEqual(rows.data[0].details,payload.details);assert.equal(rows.data[0].periodStart,payload.periodStart);
  for(const details of [[null],['invalid'],{row:payload.details[0]},[{...payload.details[0],amountCents:'100'}],[{...payload.details[1],rateBasisPoints:10001}]])assert.equal((await request('POST',{...payload,details})).status,400);
  assert.equal((await request()).data.length,1,'Reddedilen istekler kayıt oluşturmaz');
  assert.equal((await request('POST',{...payload,details:[]})).status,201,'Yalnız net tutar da kaydedilir');
 }finally{
  server.kill();await once(server,'close');
  assert.ok(resolve(dir).startsWith(resolve(tmpdir())+sep)&&dir.includes('omnigo-payment-http-'));
  await rm(dir,{recursive:true,force:true});
 }
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {resolve,join} from 'node:path';
import {createServer} from 'node:net';
import {once} from 'node:events';

const root=resolve(import.meta.dirname,'../..'),php=process.env.PHP_BINARY;
const literal=value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";
test('JSON başlangıç yetkileri, stok şekli ve boş müşteri verileri korunur',async()=>{
 assert.ok(php,'PHP_BINARY belirtilmeli.');
 const dir=mkdtempSync(join(tmpdir(),'omnigo-initial-policy-')),database=join(dir,'test.sqlite');
 const bootstrap=`require ${literal(join(root,'php/bootstrap.php'))}; $db=new \\Omnigo\\Database(['dsn'=>${literal('sqlite:'+database)}]);`;
 const init=join(dir,'init.php');
 writeFileSync(init,`<?php ${bootstrap} $db->pdo->exec(file_get_contents(${literal(join(root,'php/tests/schema.sqlite.sql'))})); \\Omnigo\\InventorySchema::install($db); $db->run("INSERT INTO users VALUES(1,'admin@example.test','Test','unused','admin',NULL)"); echo json_encode(\\Omnigo\\CustomerInitialPolicy::template($db));`);
 const initialized=spawnSync(php,[init],{encoding:'utf8'});assert.equal(initialized.status,0,initialized.stderr);
 const policy=JSON.parse(initialized.stdout);policy.inventory.mode='both';policy.pages.reports='hidden';policy.defaults.overview.view=true;
 const router=join(dir,'router.php');
 writeFileSync(router,`<?php ${bootstrap} try { $data=\\Omnigo\\Http::body(); $id=\\Omnigo\\CustomerOnboarding::create($db,['id'=>1],$data['name'],null,null,[],null,$data['initialPolicy']); $configuration=(new \\Omnigo\\CustomerPermissions($db,$id))->configuration(); \\Omnigo\\Http::json(['id'=>$id,'configuration'=>$configuration,'records'=>$db->all('SELECT kind FROM records WHERE company_id=?',[$id]),'companies'=>$db->all('SELECT id FROM companies')],201); } catch(\\Omnigo\\HttpError $error) {\\Omnigo\\Http::json(['error'=>$error->getMessage()],$error->getCode());}`);
 const probe=createServer();probe.listen(0,'127.0.0.1');await once(probe,'listening');const port=probe.address().port;await new Promise(r=>probe.close(r));
 const server=spawn(php,['-S',`127.0.0.1:${port}`,router],{stdio:['ignore','pipe','pipe']});let logs='';server.stderr.on('data',data=>logs+=data);
 const request=async initialPolicy=>{const response=await fetch(`http://127.0.0.1:${port}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:'Geçici test müşterisi',initialPolicy})});return {status:response.status,data:await response.json()};};
 try{
  let invalid;for(let attempt=0;attempt<30;attempt++){try{invalid=await request('invalid');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  assert.equal(invalid?.status,400,logs);
  const created=await request(policy);assert.equal(created.status,201,JSON.stringify(created.data)+logs);
  assert.equal(created.data.configuration.inventory.mode,'both');assert.equal(created.data.configuration.pages.reports,'hidden');assert.equal(created.data.configuration.defaults.overview.view,true);
  assert.equal(created.data.companies.length,1,'Hatalı istek yarım müşteri bırakmaz.');
  assert.deepEqual(created.data.records.map(record=>record.kind),['shipment_auto_since'],'İşletme ve başka müşteri kayıtları taşınmaz.');
  for(const mode of ['store','marketplace']){policy.inventory.mode=mode;const next=await request(policy);assert.equal(next.status,201);assert.equal(next.data.configuration.inventory.mode,mode);}
  policy.inventory.mode='invalid';const invalidMode=await request(policy);assert.equal(invalidMode.status,400);
 }finally{server.kill();await once(server,'close');rmSync(dir,{recursive:true,force:true});}
});

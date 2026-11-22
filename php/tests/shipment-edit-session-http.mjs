import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn,spawnSync} from 'node:child_process';
import {mkdtempSync,mkdirSync,cpSync,writeFileSync,rmSync,realpathSync} from 'node:fs';
import {randomBytes} from 'node:crypto';
import {tmpdir} from 'node:os';
import {resolve,join,dirname,basename} from 'node:path';
import {createServer} from 'node:net';
import {once} from 'node:events';

const root=resolve(import.meta.dirname,'../..');
const php=process.env.PHP_BINARY;
if(!php)throw new Error('PHP_BINARY belirtilmeli.');
const literal=value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";

test('HTTP Kaydet: yönetici/müşteri JSON işlemleri, tekrar, atomiklik ve firma izolasyonu',async()=>{
 const temporaryRoot=realpathSync(tmpdir());
 const dir=mkdtempSync(join(temporaryRoot,'omnigo-shipment-json-'));
 const runtime=join(dir,'php');
 for(const name of ['storage','public','tests'])mkdirSync(join(runtime,name),{recursive:true});
 cpSync(join(root,'php/src'),join(runtime,'src'),{recursive:true});
 for(const name of ['bootstrap.php','public/api.php','tests/router.php'])cpSync(join(root,'php',name),join(runtime,name));
 writeFileSync(join(runtime,'storage/backup.key'),randomBytes(32));
 const probe=createServer().listen(0,'127.0.0.1');await once(probe,'listening');
 const port=probe.address().port;await new Promise(done=>probe.close(done));
 const base=`http://127.0.0.1:${port}`,config=join(dir,'config.php');
 writeFileSync(config,`<?php return ['origin'=>${literal(base)},'database'=>['dsn'=>${literal('sqlite:'+join(dir,'test.sqlite'))}]];`);
 const run=code=>{
  const file=join(dir,'fixture.php');
  writeFileSync(file,`<?php require ${literal(join(runtime,'bootstrap.php'))};$db=new \\Omnigo\\Database((require ${literal(config)})['database']);${code}`);
  const result=spawnSync(php,[file],{encoding:'utf8'});assert.equal(result.status,0,result.stderr);return result.stdout;
 };
 run(`$db->pdo->exec(file_get_contents(${literal(join(root,'php/tests/schema.sqlite.sql'))}));
 require ${literal(join(root,'php/tests/support/http-fixture-schema.php'))};installHttpFixtureSchema($db);
 $db->run("INSERT INTO companies VALUES(1,'Test firma'),(2,'Dokunulmayacak test firma')");
 $password=\\Omnigo\\Security::hashPassword('Local-Only-Test-943!');
 $db->run("INSERT INTO users VALUES(1,'admin@example.test','Test Yönetici',?,'admin',NULL),(2,'client@example.test','Test Müşteri',?,'client',1)",[$password,$password]);`);
 const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',join(runtime,'public'),join(runtime,'tests/router.php')],{env:{...process.env,OMNIGO_CONFIG:config},stdio:['ignore','ignore','pipe']});
 let logs='';server.stderr.on('data',chunk=>logs+=chunk);
 const request=async(path,method='GET',data,auth={})=>{
  const response=await fetch(base+'/api'+path,{method,headers:{'Content-Type':'application/json',...(auth.cookie?{Cookie:auth.cookie}:{}),...(auth.csrf?{'X-CSRF-Token':auth.csrf}:{}),...(auth.operatorToken?{'X-Omnigo-Operator':auth.operatorToken}:{})},...(data===undefined?{}:{body:JSON.stringify(data)})});
  return {status:response.status,data:await response.json(),cookie:response.headers.get('set-cookie')?.split(';')[0]};
 };
 const key=()=>randomBytes(24).toString('hex');
 const scope='?company=1&city=all&provider=n11&month=2026-10';
 const draft={orderedAt:'2026-10-24T09:36:58',customerName:'Test kişi',recipientName:'Test alıcı',province:'Kayseri',district:'Kayseri',deliveryAddress:'Test adres',productName:'Test ürün',quantity:'1',invoiceAmount:'20578,35',customerType:'Bireysel'};
 try{
  let ready=false;for(let attempt=0;attempt<30;attempt++){try{await request('/status');ready=true;break;}catch{await new Promise(done=>setTimeout(done,100));}}assert.ok(ready,logs);
  const login=async(email)=>{const result=await request('/login','POST',{email,password:'Local-Only-Test-943!'});assert.equal(result.status,200,JSON.stringify(result.data));return {cookie:result.cookie,csrf:result.data.csrf};};
  const admin=await login('admin@example.test'),client=await login('client@example.test');
  const accessProbe={requestId:key(),changes:[{type:'create',requestId:key(),record:draft}]};
  assert.equal((await request('/shipments/commit'+scope,'POST',accessProbe,client)).status,403,'Yeni müşteri varsayılanlarında kayıt ekleme yetkisi kapalı olmalı');
  const operator=await request('/session/operator','POST',{name:'Test Çalışanı'},client);assert.equal(operator.status,200);assert.equal(operator.data.operatorName,'Test Müşteri','İşlem kimliği giriş yapılan hesap olmalı');client.operatorToken=operator.data.operatorToken;
  run(`$grants=\\Omnigo\\CustomerPermissionCatalog::defaults();foreach(['view','create','edit','delete'] as $action)$grants['shipments'][$action]=true;$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[1,json_encode(['workspacePolicy'=>['pages'=>['shipments'=>'shown'],'defaults'=>$grants,'users'=>(object)[]]])]);`);
  for(const path of ['/admin/shipments','/admin/shipments/commit'])assert.equal((await request(path+scope,'POST',accessProbe,client)).status,403,'Müşteri yönetici uçlarına erişememeli');
  for(const path of ['/shipments','/admin/shipments'])assert.equal((await request(path+scope,'GET',undefined,client)).status,path.startsWith('/admin/')?403:200,'Müşteri yalnız kendi panelinden kayıtları okuyabilmeli');
  assert.equal((await request('/shipments'+scope.replace('company=1','company=2'),'GET',undefined,client)).status,403,'Müşteri başka firmanın kayıtlarını okuyamamalı');
  assert.equal((await request('/shipments'+scope,'GET',undefined,admin)).data.total,0,'Reddedilen yetki istekleri kayıt oluşturmamalı');
  const untouchedBefore=run(`echo json_encode([$db->all('SELECT * FROM companies WHERE id=2'),$db->all('SELECT * FROM shipment_entries WHERE company_id=2'),$db->all('SELECT * FROM audit WHERE company_id=2')]);`);
  for(const [role,auth,path] of [['yönetici',admin,'/admin/shipments'],['müşteri',client,'/shipments']]){
   const endpoint=path+'/commit'+scope;
   const create={requestId:key(),changes:[{type:'create',requestId:key(),record:draft}]};
   assert.equal((await request(endpoint,'POST',create,{cookie:auth.cookie})).status,403,'CSRF korunmalı');
   assert.equal((await request(endpoint,'POST',create)).status,401,'Oturumsuz erişim engellenmeli');
   const created=await request(endpoint,'POST',create,auth);assert.equal(created.status,200,role+': '+JSON.stringify(created.data));assert.equal(created.data.saved,1);
   assert.equal((await request(endpoint,'POST',create,auth)).data.replayed,true);
   let listing=(await request('/shipments'+scope,'GET',undefined,auth)).data;
   const row=listing.items[0];
   const update={requestId:key(),changes:[{type:'update',id:row.id,version:row.version,record:{...draft,district:'Melikgazi'}}]};
   const saved=await request(endpoint,'POST',update,auth);assert.equal(saved.status,200,JSON.stringify(saved.data));assert.equal(saved.data.saved,1);
   const adminRows=(await request('/shipments'+scope,'GET',undefined,admin)).data.items;
   const clientRows=(await request('/shipments'+scope,'GET',undefined,client)).data.items;
   assert.deepEqual(clientRows,adminRows,'İki panel aynı kayıtları okumalı');
   const edited=adminRows.find(item=>item.id===row.id);
   assert.equal(edited.district,'Melikgazi');assert.equal(edited.invoiceCents,2057835);assert.equal(edited.quantity,1);assert.equal(edited.version,row.version+1);
   assert.equal((await request('/shipments/'+row.id+scope,'PUT',{version:edited.version,field:'rowStatus',value:'completed'},client)).status,403,'Kayıt düzenleme izni durum değiştirme izni sağlamamalı');
   assert.equal((await request('/shipments/'+row.id+scope,'PUT',{version:edited.version,field:'invoiced',value:true},client)).status,403,'Kayıt düzenleme izni fatura işaretleme izni sağlamamalı');
   assert.equal((await request(endpoint,'POST',update,auth)).data.replayed,true,'Yeniden deneme tek kez uygulanmalı');
   assert.equal((await request(endpoint,'POST',{...update,requestId:key()},auth)).status,409,'Eski sürüm reddedilmeli');
   const malformed={requestId:key(),changes:[{type:'update',id:row.id,version:edited.version,record:{...draft,district:'Test başka ilçe'}},'geçersiz işlem']};
   assert.equal((await request(endpoint,'POST',malformed,auth)).status,400);
   assert.deepEqual((await request('/shipments'+scope,'GET',undefined,admin)).data.items,adminRows,'Geçersiz sonraki işlem önceki değişikliği geri almalı');
   assert.equal((await request('/shipments/commit'+scope.replace('company=1','company=2'),'POST',update,client)).status,403,'Müşteri başka firmaya yazamamalı');
   assert.equal((await request('/admin/shipments/commit'+scope.replace('company=1','company=2'),'POST',{...update,requestId:key()},admin)).status,404,'Başka firmadan kayıt kimliği kullanılamamalı');
   const remove={requestId:key(),changes:[{type:'delete',id:row.id,version:edited.version}]};
   assert.equal((await request(endpoint,'POST',remove,auth)).status,200,'JSON silme işlemi kaydedilmeli');
   assert.equal((await request(endpoint,'POST',remove,auth)).data.replayed,true);
   assert.equal((await request('/shipments'+scope,'GET',undefined,auth)).data.total,0);
  }
  const untouchedAfter=run(`echo json_encode([$db->all('SELECT * FROM companies WHERE id=2'),$db->all('SELECT * FROM shipment_entries WHERE company_id=2'),$db->all('SELECT * FROM audit WHERE company_id=2')]);`);
  assert.equal(untouchedAfter,untouchedBefore,'Diğer firma ve işlem geçmişi değişmemeli');
 }finally{
  const exited=once(server,'exit');server.kill();await exited;
  const actual=realpathSync(dir);assert.equal(dirname(actual),temporaryRoot);assert.ok(basename(actual).startsWith('omnigo-shipment-json-'));
  rmSync(actual,{recursive:true,force:true,maxRetries:8,retryDelay:100});
 }
});

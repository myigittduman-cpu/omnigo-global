import fs from 'node:fs/promises';
import {spawn,spawnSync} from 'node:child_process';
import {createServer,request as proxyRequest} from 'node:http';
import {once} from 'node:events';
import {randomBytes} from 'node:crypto';
import {tmpdir} from 'node:os';
import path from 'node:path';
import assert from 'node:assert/strict';
const root=path.resolve(import.meta.dirname,'../..'),php=process.env.PHP_BINARY;
if(!php)throw new Error('PHP_BINARY gerekli.');
const temporary=await fs.mkdtemp(path.join(tmpdir(),'omnigo-load-'));
const runtime=temporary.replaceAll('\\','/'),processes=[],workers=[];
const phpLiteral=value=>"'"+value.replaceAll("'","\\'")+"'";
const sessions=Array.from({length:60},(_,i)=>({role:i%2?'client':'admin',token:randomBytes(32).toString('hex'),csrf:randomBytes(32).toString('hex')}));
let cursor=0;
const proxy=createServer((req,res)=>{
 const port=workers[cursor++%workers.length];
 const upstream=proxyRequest({hostname:'127.0.0.1',port,path:req.url,method:req.method,headers:req.headers},incoming=>{res.writeHead(incoming.statusCode,incoming.headers);incoming.pipe(res);});
 upstream.on('error',()=>{res.writeHead(502);res.end();});req.pipe(upstream);
});
const freePort=async()=>{const s=createServer();s.listen(0,'127.0.0.1');await once(s,'listening');const p=s.address().port;await new Promise(r=>s.close(r));return p;};
let logs='';
try{
 proxy.listen(0,'127.0.0.1');await once(proxy,'listening');const base='http://127.0.0.1:'+proxy.address().port;
 await fs.mkdir(runtime+'/storage');await fs.mkdir(runtime+'/public');await fs.mkdir(runtime+'/tests');
 await fs.cp(root+'/php/src',runtime+'/src',{recursive:true});
 for(const file of ['bootstrap.php','public/api.php','tests/router.php'])await fs.copyFile(root+'/php/'+file,runtime+'/'+file);
 await fs.writeFile(runtime+'/storage/backup.key',randomBytes(32));
 await fs.writeFile(runtime+'/config.php',`<?php return ['origin'=>${phpLiteral(base)},'database'=>['dsn'=>${phpLiteral('sqlite:'+runtime+'/test.sqlite')}]];`);
 await fs.writeFile(runtime+'/sessions.json',JSON.stringify(sessions));
 const seed=spawnSync(php,[root+'/tools/load/seed.php',runtime,root.replaceAll('\\','/'),'5000'],{encoding:'utf8'});assert.equal(seed.status,0,seed.stderr);
 for(let i=0;i<4;i++){
  const port=await freePort();workers.push(port);
  const worker=spawn(php,['-d','extension=fileinfo','-S','127.0.0.1:'+port,'-t',runtime+'/public',runtime+'/tests/router.php'],{env:{...process.env,OMNIGO_CONFIG:runtime+'/config.php'},stdio:['ignore','ignore','pipe']});
  worker.stderr.on('data',data=>{logs=(logs+data).slice(-10000);});processes.push(worker);
 }
 const request=async(route,session=sessions[0],method='GET',body)=>{
  const response=await fetch(base+'/api'+route,{method,headers:{Cookie:'og_portal_session='+session.token,'X-CSRF-Token':session.csrf,'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined,signal:AbortSignal.timeout(15000)});
  const data=await response.json();return {status:response.status,data};
 };
 for(let i=0;i<50;i++){try{if((await request('/status')).status===200)break;}catch{}await new Promise(r=>setTimeout(r,100));}
 const selection='?company=1&city=all&provider=n11&month=2026-10';
 const list=await request('/shipments'+selection);assert.equal(list.status,200,JSON.stringify(list));assert.equal(list.data.total,5000);
 assert.equal((await request('/shipments/revision?company=2',sessions[1])).status,403);
 const stages=[];
 async function stage(name,concurrency,choose,duration=15000){
  const timings=[],statuses={},routes={};let errors=0,requests=0;const start=performance.now();
  await Promise.all(Array.from({length:concurrency},async(_,i)=>{
   while(performance.now()-start<duration){const began=performance.now(),route=choose(requests++,i);try{
    const result=await request(route,sessions[i]);statuses[result.status]=(statuses[result.status]??0)+1;
    if(result.status!==200)errors++;
   }catch{errors++;}const ms=performance.now()-began;timings.push(ms);(routes[route]??=[]).push(ms);}
  }));
  const elapsed=(performance.now()-start)/1000;timings.sort((a,b)=>a-b);const percentile=(values,p)=>Math.round(values[Math.min(values.length-1,Math.floor(values.length*p))]);
  const result={name,concurrency,requests,errors,statuses,seconds:+elapsed.toFixed(2),requestsPerSecond:+(requests/elapsed).toFixed(1),p50Ms:percentile(timings,.5),p95Ms:percentile(timings,.95),p99Ms:percentile(timings,.99),routes:Object.fromEntries(Object.entries(routes).map(([route,values])=>[route,{count:values.length,p95Ms:percentile(values.sort((a,b)=>a-b),.95)}]))};stages.push(result);console.log(JSON.stringify(result));
 }
 for(const count of [10,25,50])await stage('Sürekli değişiklik kontrolü',count,()=>'/shipments/revision?company=1');
 await stage('Karma ekran okuma',25,(n,i)=>n%5===0&&i%2===0?'/admin/orders?company=1&from=2026-10-01&to=2026-10-31':n%3===0?'/shipments'+selection:'/workspace/revision?company=1');
 const id=list.data.items[0].id;
 await fs.mkdir(root+'/verification/resilience',{recursive:true});await fs.writeFile(root+'/verification/resilience/load-stages.json',JSON.stringify(stages,null,2));
 const conflicts=await Promise.all(Array.from({length:10},(_,i)=>request('/shipments/'+id+selection,sessions[i],'PUT',{field:'rowStatus',value:'completed',version:1})));
 assert.equal(conflicts.filter(r=>r.status===200).length,1,JSON.stringify(conflicts));
 assert.equal(conflicts.filter(r=>r.status===409).length,9,JSON.stringify(conflicts));
 const admin=await request('/shipments'+selection),client=await request('/shipments'+selection,sessions[1]);assert.deepEqual(admin.data.items,client.data.items);
 assert.equal(admin.data.total,5000);assert.equal(admin.data.items[0].version,2);
 const report={createdAt:new Date().toISOString(),environment:'İzole Windows / 4 PHP HTTP işçisi / SQLite WAL; canlı MySQL kapasite testi değildir.',syntheticShipments:5000,syntheticOrders:1000,realApiCalls:0,stages,correctness:{crossCompanyBlocked:true,oneWriteNineConflicts:true,adminClientEqual:true,rowCountPreserved:true},passed:stages.every(s=>s.errors===0)};
 await fs.mkdir(root+'/verification/resilience',{recursive:true});await fs.writeFile(root+'/verification/resilience/load.json',JSON.stringify(report,null,2));
 assert.ok(report.passed,'Yük altında başarısız istek var.');
}finally{
 for(const worker of processes)worker.kill();await Promise.all(processes.map(p=>p.exitCode===null?once(p,'exit'):undefined));
 proxy.closeAllConnections();await new Promise(r=>proxy.close(r));
 if(!path.basename(temporary).startsWith('omnigo-load-'))throw new Error('Geçici klasör doğrulanamadı.');
 await fs.rm(temporary,{recursive:true,force:true,maxRetries:5,retryDelay:250});
}

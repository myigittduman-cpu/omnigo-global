import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {writeFileSync} from 'node:fs';
import {join} from 'node:path';

export async function testDeliveryCorrections({request,admin,client,php,config,runtime,dir,phpLiteral}){
 const fixture=join(dir,'delivery-correction-fixture.php');
 writeFileSync(fixture,`<?php require ${phpLiteral(join(runtime,'bootstrap.php'))};
 $db=new \\Omnigo\\Database((require ${phpLiteral(config)})['database']);
 $cipher=new \\Omnigo\\DeliveryCipher(${phpLiteral(join(runtime,'storage/backup.key'))});
 $date=(new DateTimeImmutable('-2 days',new DateTimeZone('Europe/Istanbul')))->format('c');
 $key=hash('sha256',json_encode(['HTTP-CORRECTION','1']));
 $order=\\Omnigo\\DeliveryMapper::finish(['provider'=>'n11','externalId'=>'HTTP-CORRECTION:package','orderNumber'=>'HTTP-CORRECTION','buyerName'=>'Test kişi','phone'=>'','address'=>'Test adres','city'=>'Ankara','district'=>'Çankaya','createdAt'=>$date,'status'=>'Yolda','shippingState'=>'shipped','active'=>true,'products'=>[['title'=>'Test ürün','sku'=>'test','quantity'=>1]],'details'=>['orderedAt'=>$date,'customerName'=>'Test kişi','recipientName'=>'Test alıcı','deliveryAddress'=>'Test adres','province'=>'Ankara','district'=>'Çankaya','phone'=>'','products'=>[['lineId'=>'1','title'=>'Test ürün','sku'=>'test','quantity'=>1,'amountCents'=>10000,'status'=>'Shipped','cancelledQuantity'=>0]]],'shipmentItems'=>[['key'=>$key,'payload'=>['orderedAt'=>$date,'province'=>'Ankara','phone'=>'','marketplaceShipping'=>'shipped']]]]);
 $db->transaction(fn()=>(new \\Omnigo\\DeliveryRepository($db,$cipher))->ingest(1,$order,false));
 echo json_encode(['id'=>(int)$db->one("SELECT id FROM delivery_orders WHERE external_id='HTTP-CORRECTION:package'")['id'],'month'=>substr($date,0,7)]);`);
 const seeded=spawnSync(php,[fixture],{encoding:'utf8'});assert.equal(seeded.status,0,seeded.stderr);const {id,month}=JSON.parse(seeded.stdout);
 const endpoint=`/admin/delivery/orders/${id}/correction?company=1`,body={version:1,fields:{phone:'05001234567'}};
 assert.equal((await request(endpoint)).status,401);
 assert.equal((await request(endpoint,'GET',null,client)).status,403);
 assert.equal((await request(endpoint,'PATCH',body,client)).status,403);
 assert.equal((await request(endpoint,'PATCH',body,{cookie:admin.cookie})).status,403);
 assert.equal((await request(endpoint.replace('company=1','company=2'),'PATCH',body,admin)).status,404);
 const form=await request(endpoint,'GET',null,admin);assert.equal(form.status,200);assert.equal(form.data.fields.find(f=>f.key==='phone').error,true);
 const revisionPath='/workspace/revision?company=1',beforeRevision=(await request(revisionPath,'GET',null,client)).data.revision;
 const saved=await request(endpoint,'PATCH',body,admin);assert.equal(saved.status,200,JSON.stringify(saved.data));assert.equal(saved.data.ready,true);
 const adminRevision=(await request(revisionPath,'GET',null,admin)).data.revision,clientRevision=(await request(revisionPath,'GET',null,client)).data.revision;
 assert.notEqual(clientRevision,beforeRevision);assert.equal(adminRevision,clientRevision);
 assert.equal((await request(endpoint,'PATCH',body,admin)).status,409);
 const orders=await request('/admin/delivery/orders?company=1&city=Ankara&mode=shipped','GET',null,admin);
 assert.equal(orders.data.items.find(o=>o.id===id).phone,'05001234567');
 const shipmentPath=`/shipments?company=1&city=all&month=${month}&provider=n11`;
 const adminShipments=await request(shipmentPath,'GET',null,admin),clientShipments=await request(shipmentPath,'GET',null,client);
 assert.equal(adminShipments.status,200);assert.equal(clientShipments.status,200);
 assert.deepEqual(adminShipments.data.items,clientShipments.data.items);
 const entries=adminShipments.data.items;assert.equal(entries.length,1);assert.equal(entries[0].phone,'05001234567');assert.equal(entries[0].rowStatus,'shipped');
 // Keep the surrounding HTTP suite independent of this synthetic fixture.
 const clean=join(dir,'delivery-correction-cleanup.php');
 writeFileSync(clean,`<?php require ${phpLiteral(join(runtime,'bootstrap.php'))}; $db=new \\Omnigo\\Database((require ${phpLiteral(config)})['database']); $db->run('DELETE FROM delivery_orders WHERE id=?',[${id}]); $db->run('DELETE FROM shipment_entries WHERE company_id=1 AND provider=?',['n11']);`);
 const cleaned=spawnSync(php,[clean],{encoding:'utf8'});assert.equal(cleaned.status,0,cleaned.stderr);
}

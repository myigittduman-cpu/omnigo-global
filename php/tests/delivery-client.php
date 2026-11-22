<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,DeliveryCipher,DeliveryRepository,DeliveryRoutes,DeliverySelection,Security,HttpError};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function denied(callable $fn,int $status):void{try{$fn();}catch(HttpError $e){check($e->getCode()===$status,$e->getMessage());return;}throw new RuntimeException('Yetkisiz işlem engellenmedi.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);\Omnigo\ShipmentSchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");$cipher=DeliveryCipher::withKey(str_repeat('d',32));$repository=new DeliveryRepository($db,$cipher);$security=new Security($db,[]);$routes=new DeliveryRoutes($db,$security,$cipher);
$client=['id'=>2,'role'=>'client','company_id'=>1];$admin=['id'=>1,'role'=>'admin'];
foreach([1,2] as $company){$data=['provider'=>'n11','externalId'=>'sample-'.$company,'orderNumber'=>'sample-'.$company,'buyerName'=>'Örnek '.$company,'recipientName'=>'Alıcı '.$company,'phone'=>'05000000000','address'=>'Sentetik adres','city'=>'Ankara','district'=>'Çankaya','createdAt'=>'2026-10-18T10:00:00+03:00','status'=>'Sevkiyat bekliyor','active'=>true,'products'=>[['title'=>'Örnek ürün','quantity'=>1]],'issues'=>[]];$db->run('INSERT INTO delivery_orders(company_id,provider,external_id,city,active,payload,source_hash,updated) VALUES(?,?,?,?,?,?,?,?)',[$company,'n11',$data['externalId'],'Ankara',1,$cipher->seal($company,'order:n11:'.$data['externalId'],$data),'hash','2026-10-18T10:00:00+03:00']);}
$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[1,json_encode(['workspacePolicy'=>['defaults'=>['deliveries'=>['selectDocuments'=>false,'export'=>false]]]])]);
$_GET=['company'=>'1','city'=>'Ankara','mode'=>'all','dispatch'=>'available'];
if(($argv[1]??'')==='admin')$routes->handle('/api/admin/delivery/orders','GET',$admin);
if(($argv[1]??'')==='client')$routes->handle('/api/delivery/orders','GET',$client);
if(($argv[1]??'')==='revision')$routes->handle('/api/delivery/revision','GET',$client);
foreach(['/api/delivery/','/api/admin/delivery/'] as $prefix){
 foreach(['orders','revision','pdf-data','documents','documents/1','orders/1/correction','dispatch-confirmation','list-additions'] as $path)foreach(['GET','POST','PATCH','PUT','DELETE'] as $method){
  if($prefix==='/api/delivery/'&&in_array($path,['orders','revision'],true)&&$method==='GET')continue;
  denied(fn()=>$routes->handle($prefix.$path,$method,$client),403);
 }
}
$_GET['company']='2';denied(fn()=>$routes->handle('/api/delivery/orders','GET',$client),403);denied(fn()=>$routes->handle('/api/delivery/pdf-data','POST',$client),403);
denied(fn()=>$routes->handle('/api/delivery/revision','GET',$client),403);
$_GET['company']='1';$db->run('UPDATE company_profiles SET payload=? WHERE company_id=?',[json_encode(['modules'=>['shipments'=>true,'deliveries'=>false]]),1]);
foreach(['orders','revision'] as $path)denied(fn()=>$routes->handle('/api/delivery/'.$path,'GET',$client),403);
$db->run('DELETE FROM company_profiles WHERE company_id=1');
check((new Omnigo\CustomerPermissions($db,1))->allows($client,'deliveries','export'),'Görünür teslimat menüsünde varsayılan dışa aktarma açık.');
$_GET['mode']='dispatch-review';denied(fn()=>$routes->handle('/api/delivery/orders','GET',$client),400);
$snapshot=new DeliverySelection($db,$repository);$selection=['city'=>'Ankara','deliveryDate'=>'2026-10-18','orders'=>[['id'=>1,'version'=>1]]];
$before=$db->all('SELECT * FROM delivery_orders ORDER BY id');$result=$snapshot->snapshot($security->company($admin,1),$selection,true);check(count($result['orders'])===1&&$result['orders'][0]['buyerName']==='Örnek 1','Yönetici doğru firmanın evrağını hazırlayabilir');check($before===$db->all('SELECT * FROM delivery_orders ORDER BY id'),'İndirme siparişleri değiştirmez');
denied(fn()=>$snapshot->snapshot(1,[...$selection,'orders'=>[['id'=>2,'version'=>1]]],true),404);
$db->run('UPDATE delivery_orders SET active=-1,version=version+1 WHERE id=1');check(!$repository->listing(1,'Ankara','all','all',0)['items'],'İptal ortak listeden çıkar');denied(fn()=>$snapshot->snapshot(1,$selection,true),409);
echo "Teslimat evrakları: müşteri yazma/dışa aktarma engeli, firma ve modül izolasyonu, yönetici indirmesi ve eski/iptal seçim engeli geçti.\n";

<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,HepsiburadaCredentials,HepsiburadaReadClient,HepsiburadaPackageShipping,SyncFailure};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$merchant='12345678-1234-1234-1234-123456789012';$number='4060554996';$package='5512512407';
$credentials=HepsiburadaCredentials::fromArray(['merchantId'=>$merchant,'username'=>'fake','serviceKey'=>'fake','userAgent'=>'test_dev']);
$line=['id'=>'line','merchantId'=>$merchant,'orderNumber'=>$number,'name'=>'Ürün','merchantSKU'=>'SKU','quantity'=>4,'totalPrice'=>['currency'=>'TRY','amount'=>'42914.80'],'status'=>'ClaimCreated','packageNumber'=>$package,'lastStatusUpdateDate'=>'2026-09-14T14:49:51+03:00','shippingAddress'=>['city'=>'Ankara','countryCode'=>'TR']];
$raw=['orderNumber'=>$number,'orderDate'=>'2026-09-02T13:16:00+03:00','items'=>[$line]];
$calls=0;$reader=new HepsiburadaReadClient(function($url)use(&$calls,$merchant,$package){$calls++;check(str_ends_with($url,'/packages/merchantid/'.$merchant.'/packagenumber/'.$package),'Yalnız beklenen GET paket ucu');return ['status'=>200,'body'=>json_encode([['packageNumber'=>$package,'status'=>'Delivered']])];});
$state=[];$mapped=HepsiburadaPackageShipping::map($raw,$reader,$credentials,time()+25,$state);
check($mapped['order']['products'][0]['shippingState']==='delivered','Talep açılması fiziksel teslimatı silmemeli.');
check($mapped['order']['products'][0]['sourceStatus']==='ClaimCreated','Talep durumu ayrıca korunmalı.');
check($mapped['order']['grossCents']===4291480&&$mapped['order']['cancelCents']===0,'Tutar ve iptal miktarı değişmemeli.');
$delivery=$mapped['deliveries'][0];check(!$delivery['active']&&$delivery['shipmentItems'][0]['payload']['marketplaceShipping']==='delivered','Teslimat evraklarından çıkıp sevkiyata teslim durumu gitmeli.');
HepsiburadaPackageShipping::map($raw,$reader,$credentials,time()+25,$state);check($calls===1,'Aynı pakete bir dakikada tekrar istek yapılmamalı.');
$wrong=new HepsiburadaReadClient(fn()=>['status'=>200,'body'=>json_encode([['packageNumber'=>'123','status'=>'Delivered']])]);
try{$empty=[];HepsiburadaPackageShipping::map($raw,$wrong,$credentials,time()+25,$empty);throw new RuntimeException('Farklı paket kabul edildi.');}catch(SyncFailure $e){check($e->httpStatus===422,'Farklı paket reddedilmeli.');}
$cancelled=[...$raw,'items'=>[[...$line,'status'=>'CancelledByCustomer']]];$mapped=HepsiburadaPackageShipping::map($cancelled,$reader,$credentials,time()+25,$state);
check($mapped['order']['products'][0]['shippingState']==='cancelled'&&$calls===1,'İptal, eski paket teslim bilgisiyle bozulmamalı.');
$missing=new HepsiburadaReadClient(fn()=>['status'=>404]);$empty=[];$mapped=HepsiburadaPackageShipping::map($raw,$missing,$credentials,time()+25,$empty);
check($mapped['order']['products'][0]['shippingState']==='review','Paket doğrulanamazsa sahte teslimat üretilmemeli.');
$limited=new HepsiburadaReadClient(fn()=>['status'=>429,'retryAfter'=>120]);
try{$empty=[];HepsiburadaPackageShipping::map($raw,$limited,$credentials,time()+25,$empty);throw new RuntimeException('Limit kabul edildi.');}catch(SyncFailure $e){check($e->httpStatus===429&&$e->retryAfter===120,'Limit kuyruk yeniden denemesine aktarılmalı.');}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));\Omnigo\RecentOrderSchema::install($db);$db->run("INSERT INTO companies(id,name) VALUES(1,'Bir'),(2,'İki')");
foreach([1,2] as $company)$db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$company,'hepsiburada',$number,json_encode(['products'=>[['shippingState'=>'review']]]),gmdate('c')]);
$state=[];HepsiburadaPackageShipping::schedule($db,1,$state);check(array_keys($state['pendingOrders'])===[(int)$number],'Geçmiş kontrol siparişi mevcut kuyrukta tekrar incelenmeli.');
echo "Hepsiburada fiziksel paket teslimatı, talep ayrımı, tutar, kuyruk, limit ve müşteri kapsamı geçti.\n";

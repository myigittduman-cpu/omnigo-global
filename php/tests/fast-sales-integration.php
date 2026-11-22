<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
require_once __DIR__.'/fixtures/financial-visibility.php';
use Omnigo\{Database,Repository,IntegrationVault,MarketplaceConnection,MarketplaceReadClient,IdefixSyncAdapter,IdefixReturnClient,QuestionReadTransport,FinancialRefreshBatch,DeliverySchema,DeliveryCipher,DeliveryRepository,ShipmentSchema,NotificationSchema,SyncFailure};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);ShipmentSchema::install($db);NotificationSchema::migrate($db);
$repo=new Repository($db);$company=$repo->createCompany('Örnek');$key=tempnam(sys_get_temp_dir(),'fast-sales');file_put_contents($key,random_bytes(32));
try{
 $vault=new IntegrationVault($db,$key);(new MarketplaceConnection($db,$vault,'idefix'))->save($company,['version'=>0,'vendorId'=>'123','apiKey'=>'test','apiSecret'=>'secret']);
 $db->run("UPDATE records SET payload=? WHERE company_id=? AND kind='sync_idefix'",[json_encode(['startDate'=>gmdate('c',time()-3600)]),$company]);
 $cancel=false;$refund=false;$broken=false;$count=1;$calls=0;
 $source=new MarketplaceReadClient(function()use(&$cancel,&$count,&$calls){
  $calls++;$items=[];for($i=1;$i<=$count;$i++)$items[]=['id'=>$i,'vendorId'=>123,'orderNumber'=>'TEST-'.$i,'orderDate'=>gmdate('c',time()-30),'status'=>$cancel&&$i===1?'shipment_cancelled':'shipment_in_cargo','customerContactName'=>'Örnek Alıcı','discountedTotalPrice'=>'100.00','shippingAddress'=>['fullName'=>'Örnek Alıcı','phone'=>'05000000000','city'=>'Ankara','county'=>'Çankaya','fullAddress'=>'Örnek adres'],'items'=>[['id'=>$i*100,'currency'=>'TRY','productName'=>'Örnek ürün','discountedTotalPrice'=>'100.00']]];
  return ['status'=>200,'body'=>json_encode(['items'=>$items,'currentPage'=>1,'pageCount'=>1])];
 });
 $returns=new IdefixReturnClient(new QuestionReadTransport(function()use(&$refund,&$broken){
  if($broken)return ['status'=>429,'retryAfter'=>120];
  $items=$refund?[['orderNumber'=>'TEST-2','items'=>[['id'=>91,'orderLineId'=>200,'state'=>'approved','discountedTotalPrice'=>'100.00']]]]:[];
  return ['status'=>200,'body'=>json_encode(['items'=>$items,'totalCount'=>count($items),'pageCount'=>count($items),'currentPage'=>1])];
 }));
 $reader=new IdefixSyncAdapter($db,$vault,new DeliveryRepository($db,new DeliveryCipher($key)),$source,$returns);
 $batch=new FinancialRefreshBatch($db,'idefix',$reader,fn(int $seconds)=>null);
 $checkTotals=function(int $gross,int $cancelled,int $returned)use($db,$repo,$company){
  $month=(new DateTimeImmutable('now',new DateTimeZone('Europe/Istanbul')))->format('Y-m');
allowFinancialVisibility($db,$company);
  $admin=$repo->dashboard(['role'=>'admin'],$company,$month);$client=$repo->dashboard(['role'=>'client','company_id'=>$company],$company,$month);
  $select=fn($dashboard)=>array_values(array_filter($dashboard['sales'],fn($sale)=>$sale['channel']==='idefix'))[0];
  $a=$select($admin);$c=$select($client);
  foreach(['grossCents'=>$gross,'cancelCents'=>$cancelled,'returnCents'=>$returned] as $field=>$expected)check($a[$field]===$expected,'Yönetici panelinde doğru tutar: '.$field);
  check($c['salesTurnoverCents']===$a['salesTurnoverCents']&&$c['grossCents']===$a['salesTurnoverCents']&&$c['netCents']===$gross-$cancelled-$returned&&!array_key_exists('cancelCents',$c)&&!array_key_exists('returnCents',$c),'Müşteri satış cirosunu iptal/iade kırılımı olmadan alır');
  check($client['marketplaceSales']['currentMonthTurnoverCents']===$admin['marketplaceSales']['periodMetrics']['all']['month']['turnoverCents']
    &&$client['marketplaceSales']['currentMonthTurnoverCents']===$gross,'Cari ay kartı, her yeni aktarımda aynı canlı brüt toplamı alır');
  check($a['refundsVerified']===true&&$c['refundsVerified']===true,'İade kontrolü her iki panelde tamamlandı');
 };
 $batch->syncReadOnly($company,time()+45);$checkTotals(10000,0,0);
 $count=2;$batch->syncReadOnly($company,time()+45);$checkTotals(20000,0,0);
 $cancel=true;$batch->syncReadOnly($company,time()+45);$checkTotals(20000,10000,0);
 $refund=true;$broken=true;
 try{$batch->syncReadOnly($company,time()+45);throw new RuntimeException('Eksik iade taraması yayımlandı');}catch(SyncFailure $error){check($error->retryAfter===120,'Hız sınırı korundu');}
 $checkTotals(20000,10000,0);$broken=false;$batch->syncReadOnly($company,time()+45);$checkTotals(20000,10000,10000);
 $batch->syncReadOnly($company,time()+45);$checkTotals(20000,10000,10000);
 check($calls===5,'İade devamında sipariş tekrar taranmaz; tamamlanan çevrim tek kez okunur');
 echo "Hızlı mali aktarım: yeni sipariş, iptal, onaylı iade, iki panel eşitliği, eksik tarama koruması ve tekrarsız toplamlar geçti.\n";
}finally{unlink($key);}

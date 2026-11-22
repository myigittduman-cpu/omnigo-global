<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,DeliverySchema,DeliveryCipher,IntegrationVault,N11ReturnClient,N11SnapshotStore,ReturnBuyerIdentity,OrderDetails,ReturnRequestList};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));DeliverySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'İlk firma'),(2,'Diğer firma')");
$key=tempnam(sys_get_temp_dir(),'return-buyer-');file_put_contents($key,random_bytes(32));
try{
 $cipher=new DeliveryCipher($key);$vault=new IntegrationVault($db,$key);$config=['revision'=>'r1','enabled'=>true];$vault->save(1,$config,'n11');
 $buyers=new ReturnBuyerIdentity($cipher);$store=new N11SnapshotStore($db,$vault,null,$buyers);
 $from='2026-09-01T00:00:00+03:00';$to='2026-10-24T00:00:00+03:00';
 $xml='<Envelope><Body><ClaimReturnListResponse><result><status>success</status></result><claimReturnList><claimReturn><claimReturnId>91</claimReturnId><orderNumber>1001</orderNumber><productId>11</productId><quantity>1</quantity><finalPrice>123.45</finalPrice><status>APPROVED</status><requestDate>04/09/2026</requestDate><buyerName>Örnek Alıcı</buyerName><buyerPhone>GİZLİ TELEFON</buyerPhone><buyerEmail>GİZLİ EPOSTA</buyerEmail></claimReturn></claimReturnList><pagingData><currentPage>0</currentPage><pageCount>1</pageCount><totalCount>1</totalCount></pagingData></ClaimReturnListResponse></Body></Envelope>';
 $claims=N11ReturnClient::parse($xml,0)['claims'];
 check($claims[0]['buyerName']==='Örnek Alıcı'&&!str_contains(json_encode($claims,JSON_UNESCAPED_UNICODE),'GİZLİ'),'Yalnız gerekli alıcı adı okunur');
 $state=['scan'=>['from'=>$from,'through'=>$to],'sellerId'=>55];
 $store->commit(1,null,$config,$state,['type'=>'returns','items'=>$claims],true,false);
 $stage=$db->one("SELECT payload FROM records WHERE kind='stage_n11'")['payload'];
 check(!str_contains($stage,'Alıcı')&&!str_contains($stage,'buyerName'),'Geçici iade sayfasında ad açık saklanmaz');
 $row=$store->state(1);$state=json_decode($row['payload'],true);
 // Duplicate claims from another page must not become financial conflicts due to random encryption nonces.
 $store->commit(1,$row,$config,$state,['type'=>'returns','items'=>$claims],false,true);
 $stored=$store->state(1);check(!str_contains($stored['payload'],'Alıcı'),'Yayımlanan iade kaydında ad şifreli');
 $state=json_decode($stored['payload'],true);$item=$state['returnRequests']['items'][0];
 check(count($state['returnRequests']['items'])===1&&$buyers->name(1,'n11',$item)==='Örnek Alıcı','Eski sipariş olmadan tek iade ve doğru alıcı');
 check($buyers->name(2,'n11',$item)===null&&$buyers->name(1,'pttavm',$item)===null,'Firma/pazaryeri kapsamı korunur');
 check($buyers->name(1,'n11',[...$item,'id'=>'92'])===null&&$buyers->name(1,'n11',[...$item,'orderNumber'=>'1002'])===null,'Talep ve sipariş kimliği değiştirilemez');
 check(!$db->all('SELECT * FROM delivery_orders')&&!$db->all("SELECT * FROM records WHERE kind='order_n11'"),'Eksik ad için eski sipariş/teslimat uydurulmaz');
 $sales=$db->all("SELECT payload FROM records WHERE kind='sales'");foreach($sales as $sale)check(json_decode($sale['payload'],true)['grossCents']===0,'Eski iade güncel satış toplamını değiştirmez');
 $list=new ReturnRequestList($db,new OrderDetails($db,$cipher));$result=$list->listing(['role'=>'admin'],1,['provider'=>'n11','search'=>'Örnek Alıcı']);
 check($result['total']===1&&$result['items'][0]['customerName']==='Örnek Alıcı','Alıcı adı listede görünür ve aranabilir');
 check(!str_contains(json_encode($result),'buyerIdentity')&&!str_contains(json_encode($result),'cipher'),'Şifreli zarf istemciye çıkmaz');
 $db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(2,'sync_n11',?,?)",[$stored['payload'],gmdate('c')]);
 check($list->listing(['role'=>'admin'],2,['provider'=>'n11'])['items'][0]['customerName']===null,'Başka firmaya kopyalanmış kayıt ad sızdırmaz');
 echo "n11 eski iade: API alıcısı, şifreli ara/yayımlanmış kayıt, tekilleştirme, firma/sipariş kapsamı, arama ve değişmeyen satış hesapları geçti.\n";
}finally{unlink($key);}

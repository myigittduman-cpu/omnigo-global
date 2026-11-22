<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{CustomerPermissionRoutes,CustomerStandardPermissions,CustomerTableVisibility,Database,ReturnRequestMapper as Mapper,ReturnRequestSnapshot as Snapshot,ReturnRequestList,ReturnRequestRoutes,Security,HttpError};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function denied(callable $fn,int $code):void{try{$fn();throw new RuntimeException('Reddedilmedi');}catch(HttpError $e){check($e->getCode()===$code,'Yetki/filtre kodu');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));Omnigo\DeliverySchema::install($db);$db->run("INSERT INTO companies VALUES(1,'Örnek'),(2,'Başka firma')");
$now=gmdate('c');$from=gmdate('c',time()-86400);$admin=['role'=>'admin'];$client=['role'=>'client','company_id'=>1];
$claim=['claimReturnId'=>'001','orderNumber'=>'000123','productId'=>'99','status'=>'REQUESTED','requestDate'=>'18/10/2026 21:32:11','quantity'=>2,'amountCents'=>12000];
$item=Mapper::n11($claim);check($item['orderNumber']==='000123'&&$item['createdAt']==='2026-10-18T21:32:11+03:00','Kimlik ve Türkiye tarihi');
check(Mapper::date('31/02/2026')===null&&Mapper::date('uygunsuz')===null,'Bozuk tarih tahmin edilmez');
$state=[];Snapshot::publish($state,[$item,$item],$from,$now);check(count($state['returnRequests']['items'])===1,'Aynı talep çift sayılmaz');$saved=$state['returnRequests'];
Snapshot::publish($state,[$item,[...$item,'status'=>'APPROVED']],$from,$now);check($state['returnRequests']===$saved&&isset($state['returnRequestError']),'Tutarsız tam tarama önceki kaydı bozmaz');
Snapshot::publish($state,[[...$item,'status'=>'APPROVED']],$from,$now);check($state['returnRequests']['items'][0]['status']==='APPROVED'&&!isset($state['returnRequestError']),'Sonraki tam tarama durumu değiştirir');
Snapshot::publish($state,[],$from,$now);check($state['returnRequests']['items']===[],'Doğrulanmış boş tarama eski talepleri temizler');
$items=[];for($i=0;$i<43;$i++)$items[]=[...$item,'id'=>(string)($i+1)];Snapshot::publish($state,$items,$from,$now);
$save=static fn($company,$kind,$data)=>$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,json_encode($data),$now]);
$save(1,'sync_n11',$state);$save(2,'sync_n11',['returnRequests'=>['items'=>[[...$item,'id'=>'OTHER']],'checkedAt'=>$now,'from'=>$from,'through'=>$now]]);
$save(1,'order_n11',['externalId'=>'000123','products'=>[['productId'=>'99','title'=>'Eşleşen ürün']]]);
$save(1,'sync_pazarama',['lastSuccessAt'=>$now]);$save(1,'order_pazarama',['externalId'=>'P1','returnCents'=>12345,'products'=>[['title'=>'Örnek ürün','returnCents'=>12345]]]);
$list=new ReturnRequestList($db);$result=$list->listing($admin,1,[]);check($result['total']===44&&count($result['items'])===20&&$result['hasMore'],'20li sayfalama');
check(!str_contains(json_encode($result),'OTHER'),'Firmalar birbirine karışmaz');
$second=$list->listing($admin,1,['page'=>2]);check(count($second['items'])===4&&!$second['hasMore'],'Son sayfa doğru');
$filtered=$list->listing($admin,1,['status'=>'pending','provider'=>'n11','search'=>'000123']);check($filtered['total']===43,'Pazaryeri, durum, sipariş filtresi');
check($list->listing($admin,1,['provider'=>'n11','search'=>'Eşleşen ürün'])['total']===43,'Ürün adı doğrulanmış sipariş satırından gelir ve aranabilir');
check(Mapper::n11([...$claim,'requestDate'=>'18/10/2026'])['dateOnly']===true,'Kaynak yalnız gün bildirirse saat uydurulmaz');
$sources=array_column($result['sources'],null,'key');check($sources['hepsiburada']['state']==='unavailable'&&$sources['pazarama']['coverage']==='completed_only','Eksik veri sıfır talep olarak sunulmaz');
$state['failure']=['code'=>429];$db->run("UPDATE records SET payload=? WHERE company_id=1 AND kind='sync_n11'",[json_encode($state)]);
$failed=$list->listing($admin,1,['provider'=>'n11']);check($failed['total']===43&&$failed['sources'][0]['state']==='stale','Kesintide eski liste korunur ama güncel gösterilmez');
denied(fn()=>$list->listing($client,1,[]),403);denied(fn()=>$list->listing($admin,1,['provider'=>'unknown']),400);denied(fn()=>$list->listing($admin,1,['page'=>-1]),400);
$route=new ReturnRequestRoutes($db,new Security($db,[]));denied(fn()=>$route->handle('/api/admin/returns','GET',$client),403);denied(fn()=>$route->handle('/api/admin/returns','POST',$admin),405);
$hb=Mapper::hepsiburada(['id'=>'1','orderNumber'=>'2','lineId'=>'3','status'=>'NewRequest','orderedAt'=>$now,'quantity'=>1,'amountCents'=>0]);check($hb['amountCents']===null&&$hb['createdAt']===null,'Bilinmeyen talep tutarı sıfır ve sipariş tarihi talep tarihi yapılmaz');
foreach(['NewRequest','Accepted','Refunded'] as $status)check(Mapper::status('hepsiburada',$status)[0]===['NewRequest'=>'pending','Accepted'=>'approved','Refunded'=>'refunded'][$status],'Talep/onay/ödeme ayrımı');
$ide=Mapper::idefix(['orderNumber'=>'O1','customerName'=>'Gizli kişi'],['id'=>5,'orderLineId'=>7,'state'=>'ready','productName'=>'Ürün','customerNote'=>'Gizli not','discountedTotalPrice'=>'15.20']);
check($ide['amountCents']===1520&&$ide['quantity']===null&&!str_contains(json_encode($ide),'Gizli'),'Ürün bilgisi, bilinmeyen miktar, kişisel veri sınırı');
$hbRequests=[
 [...$hb,'id'=>'invoice','status'=>'Accepted','claimType'=>'MissingInvoice'],
 [...$hb,'id'=>'invoice-case','status'=>'Refunded','claimType'=>' missinginvoice '],
 [...$hb,'id'=>'return','status'=>'Accepted','claimType'=>'Return'],
 [...$hb,'id'=>'undelivered','status'=>'Refunded','claimType'=>'UndeliveredProduct'],
 [...$hb,'id'=>'replacement','status'=>'NewRequest','claimType'=>'RenewProduct'],
 [...$hb,'id'=>'replacement-accepted','status'=>'Accepted','claimType'=>' renewproduct '],
 [...$hb,'id'=>'replacement-refunded','status'=>'Refunded','claimType'=>'RenewProduct','amountCents'=>1500],
 [...$hb,'id'=>'pending-return','status'=>'NewRequest','claimType'=>'Return'],
];
$beforePending=$list->pendingCount(1);
$save(1,'sync_hepsiburada',['financial'=>['returnRequests'=>['items'=>$hbRequests,'checkedAt'=>$now]]]);
$beforeHb=$db->one("SELECT payload FROM records WHERE company_id=1 AND kind='sync_hepsiburada'")['payload'];
$hbList=$list->listing($admin,1,['provider'=>'hepsiburada']);
check(array_column($hbList['items'],'id')===['pending-return','replacement-refunded','return','undelivered'],
 'Fatura ve ürün temini talepleri ayrılır. Gerçek iadeler, para iadesi ve teslim edilemedi kaydı korunur.');
check($list->pendingCount(1)===$beforePending+1,'Menü sayacı ürün teminini değil gerçek bekleyen iadeyi sayar.');
check($db->one("SELECT payload FROM records WHERE company_id=1 AND kind='sync_hepsiburada'")['payload']===$beforeHb,'Görüntüleme kaynak geçmişini değiştirmez');
$ideRequests=[];
foreach(['SINGLE','MULTI','DUPLICATE','MISSING','EXPLICIT','OTHER-COMPANY'] as $number)$ideRequests[]=[...$ide,'id'=>$number,'orderNumber'=>$number,'lineId'=>'7','quantity'=>$number==='EXPLICIT'?2:null];
$save(1,'sync_idefix',['returnRequests'=>['items'=>$ideRequests,'checkedAt'=>$now]]);
foreach(['SINGLE'=>[1],'MULTI'=>[3],'DUPLICATE'=>[1,1],'EXPLICIT'=>[3]] as $number=>$quantities){
 $save(1,'order_idefix',['externalId'=>$number,'products'=>array_map(static fn($quantity)=>['lineId'=>'7','quantity'=>$quantity,'title'=>'Aynı ürün'],$quantities)]);
}
$save(2,'order_idefix',['externalId'=>'OTHER-COMPANY','products'=>[['lineId'=>'7','quantity'=>1]]]);
$ideList=array_column($list->listing($admin,1,['provider'=>'idefix'])['items'],null,'orderNumber');
check($ideList['SINGLE']['quantity']===1,'idefix kesin eşleşen tek birim satırında adet tamamlanır');
foreach(['MULTI','DUPLICATE','MISSING','OTHER-COMPANY'] as $number)check($ideList[$number]['quantity']===null,'idefix belirsiz/başka firma satırından adet tahmin edilmez: '.$number);
check($ideList['EXPLICIT']['quantity']===2,'Kaynağın açık adet bilgisi değiştirilmez');
foreach($ideList as $row)check($row['amountCents']===1520&&$row['status']==='ready','idefix tutar ve durum bilgisi korunur');
$scopedClient=['id'=>2,'role'=>'client','company_id'=>1];
$scoped=CustomerStandardPermissions::grants();
$scoped['returns']['view']=true;
$scoped['returns'][CustomerTableVisibility::RETURNS]=true;
$profile=['workspacePolicy'=>['pages'=>['returns'=>'all_roles'],'users'=>['2'=>$scoped]]];
$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[1,json_encode($profile)]);
$_GET=['company'=>'1'];
CustomerPermissionRoutes::enforce($db,new Security($db,[]),$scopedClient,'/api/returns','GET');
$customerReturns=$list->listing($scopedClient,1,[],includeCancellations:true);
check($customerReturns['total']>0&&array_unique(array_column($customerReturns['items'],'type'))===['return'],'İade izni yalnız iade satırlarını getirir');
denied(fn()=>$list->listing($scopedClient,1,['type'=>'cancellation'],includeCancellations:true),403);
denied(fn()=>$list->listing($scopedClient,2,[],includeCancellations:true),403);
denied(fn()=>$route->handle('/api/admin/returns','GET',$scopedClient),403);
$profile['workspacePolicy']['users']['2']['returns']=['view'=>true,CustomerTableVisibility::CANCELLATIONS=>true];
$db->run('UPDATE company_profiles SET payload=? WHERE company_id=1',[json_encode($profile)]);
$customerCancellations=$list->listing($scopedClient,1,[],includeCancellations:true);
check($customerCancellations['sources']===[]&&$customerCancellations['items']===[],'İptal izni iade kaynak durumlarını ve kayıtlarını açmamalı');
denied(fn()=>$list->listing($scopedClient,1,['type'=>'return'],includeCancellations:true),403);
echo "İade listesi: firma/rol sınırı, filtre, fatura türü ayrımı, kesin idefix adedi, tutar/durum koruması ve tekilleştirme geçti.\n";

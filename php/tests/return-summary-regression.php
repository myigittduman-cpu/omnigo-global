<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,RecentOrderSchema,Repository,ReturnRequestEnrichment,ReturnRequestList,ReturnRequestMapper,MarketplaceSalesSummary,SalesAnalytics};
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));RecentOrderSchema::install($db);
$repo=new Repository($db);$company=$repo->createCompany('Test');$other=$repo->createCompany('Diğer');
$clock=new DateTimeImmutable('2026-10-24T12:00:00+03:00');$now=$clock->format(DATE_ATOM);
$save=static fn($firm,$kind,$data)=>$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$firm,$kind,json_encode($data),$now]);
$product=['lineId'=>'L1','sku'=>'P1','title'=>'Ürün','quantity'=>2,'netQuantity'=>0,'grossCents'=>20000,'cancelCents'=>0,'returnCents'=>20000,'netCents'=>0];
check(ReturnRequestEnrichment::completedQuantity(['products'=>[$product]])===2,'Tam iade adedi kaynaktan alınır');
check(ReturnRequestEnrichment::completedQuantity(['products'=>[[...$product,'netQuantity'=>1,'returnCents'=>10000]]])===1,'Kesin net adet yalnız ilgili ürünün iadesini verir');
foreach([
 [...$product,'netQuantity'=>null,'returnCents'=>10000],
 [...$product,'quantity'=>null],
 [...$product,'netQuantity'=>0,'cancelCents'=>10000,'returnCents'=>10000],
 [...$product,'refundedQuantity'=>3],
] as $ambiguous)check(ReturnRequestEnrichment::completedQuantity(['products'=>[$ambiguous]])===null,'Belirsiz, iptalle karışan veya fazla adet tahmin edilmez');
check(ReturnRequestEnrichment::completedQuantity(['products'=>[$product,[...$product,'returnCents'=>0]]])===2,'İadesiz ürünler sayılmaz');
$save($company,'order_koctas',['externalId'=>'K1','returnCents'=>20000,'products'=>[$product]]);
$save($other,'order_koctas',['externalId'=>'K2','returnCents'=>20000,'products'=>[$product]]);
$request=['id'=>'P1:L1','orderNumber'=>'P1','lineId'=>'L1','status'=>'8','quantity'=>null,'product'=>'','amountCents'=>null];
$save($company,'sync_pazarama',['returnRequests'=>['items'=>[$request,[...$request,'id'=>'P2:L1','orderNumber'=>'P2'],[...$request,'id'=>'P3:L1','orderNumber'=>'P3','status'=>'7']],'checkedAt'=>$now]]);
$save($company,'order_pazarama',['externalId'=>'P1','returnCents'=>20000,'products'=>[$product]]);
$save($other,'order_pazarama',['externalId'=>'P2','returnCents'=>20000,'products'=>[$product]]);
$save($company,'order_pazarama',['externalId'=>'P3','returnCents'=>0,'products'=>[$product]]);
$list=new ReturnRequestList($db);$koctas=$list->listing(['role'=>'admin'],$company,['provider'=>'koctas']);
check($koctas['total']===1&&$koctas['items'][0]['quantity']===2&&$koctas['items'][0]['amountCents']===20000,'Koçtaş adedi tamamlanır, tutar ve firma sınırı korunur');
$pazarama=array_column($list->listing(['role'=>'admin'],$company,['provider'=>'pazarama'])['items'],null,'orderNumber');
check($pazarama['P1']['quantity']===2&&$pazarama['P1']['amountCents']===null,'Pazarama kesin satır adedi tamamlanır, talep tutarı uydurulmaz');
check($pazarama['P2']['quantity']===null&&$pazarama['P3']['quantity']===null,'Başka müşterinin ve henüz onaylanmayan talebin adedi kullanılmaz');
$duplicate=(new ReturnRequestEnrichment($db))->apply($company,'pazarama',[[...$request,'lineId'=>'missing']]);check($duplicate[0]['quantity']===null,'Yanlış satırdan eşleştirme yapılmaz');
check(ReturnRequestMapper::status('n11','CANCELLED')===['cancelled','İade talebi kapandı (iptal / ret)'],'n11 ortak kapanış kodu kesin sipariş iptali veya iade onayı sayılmaz');
check(ReturnRequestMapper::status('n11','APPROVED')[0]==='approved'&&ReturnRequestMapper::status('n11','DENIED')[0]==='rejected','Diğer n11 durumları korunur');
$save($company,'sync_pttavm',['startDate'=>'2026-10-01T00:00:00+03:00','watermark'=>$now,'refundsVerified'=>false]);
$ptt=['externalId'=>'T1','createdAt'=>'2026-10-23T11:00:00+03:00','orders'=>1,'grossCents'=>20000,'cancelCents'=>0,'returnCents'=>20000,'products'=>[$product]];
foreach([[$company,$ptt],[$company,[...$ptt,'externalId'=>'T2','returnCents'=>0,'products'=>[[...$product,'returnCents'=>0,'netCents'=>20000,'netQuantity'=>2]]]],[$other,[...$ptt,'externalId'=>'OTHER']]] as [$firm,$order])$db->run('INSERT INTO recent_orders(company_id,provider,external_id,payload,updated) VALUES(?,?,?,?,?)',[$firm,'pttavm',$order['externalId'],json_encode($order),$now]);
// A superseded legacy copy must not be counted instead of or alongside current orders.
$save($company,'order_pttavm',[...$ptt,'grossCents'=>999999]);
$before=$db->all('SELECT * FROM records');$beforeRecent=$db->all('SELECT * FROM recent_orders');
$summary=(new MarketplaceSalesSummary($db))->dashboard($company,[['name'=>'PTT AVM']],'2026-10',$clock);
$card=$summary['channels'][0];$month=$card['periods']['month'];
check($card['refundsVerified']&&$month['grossCents']===40000&&$month['returnCents']===20000&&$month['amountCents']===20000,'PTT iade ve net sipariş durumlarından gösterilir');
$sale=MarketplaceSalesSummary::idefixSale($summary,'2026-10','PTT AVM');check($sale['refundsVerified']&&!str_contains($sale['source'],'doğrulanmadı'),'Eski ayrı iade servisi bekleme metni kaldırılır');
foreach([['role'=>'admin'],['role'=>'client','company_id'=>$company]] as $user){
 $report=(new SalesAnalytics($db))->report($user,$company,'PTT AVM','2026-10-01','2026-10-24',0,25,$clock);
 check($report['available']&&$report['totals']['grossCents']===40000&&$report['totals']['returnCents']===20000&&$report['totals']['netCents']===20000&&$report['productCount']===1,'İki panelin ayrıntı raporu güncel sipariş kaynağı ve özetle eşleşir');
}
check($before===$db->all('SELECT * FROM records')&&$beforeRecent===$db->all('SELECT * FROM recent_orders'),'Görüntüleme kayıt, bağlantı ve finansal geçmişi değiştirmez');
echo "İade adetleri, n11 açıklaması, PTT güncel özet/ayrıntı, müşteri ayrımı ve salt okunur davranış geçti.\n";

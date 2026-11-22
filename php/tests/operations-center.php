<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,OperationSchema,OperationTargets,OperationDossier,OperationFiles,AttentionCenter,AttentionSources,MarketplaceGate,ShipmentSchema,InventorySchema,DeliveryCipher,HttpError,ReturnRequestList,OperationRoutes,Security,BackupFiles};
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function denied(callable $call,int $status):void{try{$call();throw new RuntimeException('İşlem kabul edildi');}catch(HttpError $e){check($e->getCode()===$status,$e->getMessage().' (beklenen '.$status.')');}}
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));Omnigo\DeliverySchema::install($db);ShipmentSchema::install($db);InventorySchema::install($db);OperationSchema::install($db);MarketplaceGate::install($db);
$db->run("INSERT INTO companies VALUES(1,'Birinci'),(2,'İkinci')");$db->run("INSERT INTO users VALUES(1,'admin@example.test','Omnigo Yetkili','x','admin',NULL),(2,'other@example.test','Diğer Yetkili','x','admin',NULL),(3,'client@example.test','Müşteri','x','client',1)");
$admin=['id'=>1,'role'=>'admin','name'=>'Omnigo Yetkili','token'=>str_repeat('a',64)];$other=['id'=>2,'role'=>'admin','name'=>'Diğer Yetkili','token'=>str_repeat('b',64)];$client=['id'=>3,'role'=>'client','company_id'=>1];$cipher=DeliveryCipher::withKey(random_bytes(32));
$db->run('INSERT INTO sessions VALUES(?,?,?,?),(?,?,?,?)',[$admin['token'],1,'csrf',time()+86400,$other['token'],2,'csrf',time()+86400]);
$folder=sys_get_temp_dir().'/omnigo-operation-test-'.bin2hex(random_bytes(8));$files=new OperationFiles($cipher,$folder);$dossiers=new OperationDossier($db,$cipher,$files);$targets=new OperationTargets($db,$cipher);$now=gmdate('c');$old=gmdate('Y-m-d H:i:s',time()-12*86400);
$payload=['orderNumber'=>'000123','productName'=>'Örnek ürün','customerName'=>'Örnek alıcı'];
foreach([1,2] as $company){$db->run("INSERT INTO shipment_entries(company_id,city,provider,month,payload,created,updated,ordered_at) VALUES(?,'Ankara','n11',?,'{}',?,?,?)",[$company,substr($old,0,7),$now,$now,$old]);$id=$db->insertId();$db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal($company,'shipment:'.$id,$payload),$id]);}
$original=$db->all('SELECT * FROM shipment_entries');
$target=$targets->resolve($admin,1,['kind'=>'shipment','id'=>1]);$foreign=$targets->resolve($admin,2,['kind'=>'shipment','id'=>2]);denied(fn()=>$targets->resolve($admin,2,['kind'=>'shipment','id'=>1]),404);denied(fn()=>$targets->resolve($client,1,['kind'=>'shipment','id'=>1]),403);
$claim=['id'=>'CLAIM','orderNumber'=>'000123','lineId'=>'L1','status'=>'REQUESTED','quantity'=>1,'amountCents'=>null,'product'=>'Örnek ürün','reason'=>'Hasar','createdAt'=>$now];
$db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[1,'sync_n11',json_encode(['returnRequests'=>['items'=>[$claim],'checkedAt'=>$now,'through'=>$now]]),$now]);
$returns=(new ReturnRequestList($db))->listing($admin,1,['provider'=>'n11']);$ref=$returns['items'][0]['dossierRef'];$returnTarget=$targets->resolve($admin,1,['kind'=>'return','provider'=>'n11','ref'=>$ref]);check($returnTarget['key']===$target['key'],'Aynı mağaza/pazaryeri/sipariş teslimat ve iade dosyasını paylaşır');
denied(fn()=>$targets->resolve($admin,2,['kind'=>'return','provider'=>'n11','ref'=>$ref]),404);
$image=imagecreatetruecolor(30,20);ob_start();imagepng($image);$photo=ob_get_clean();imagedestroy($image);
$input=['version'=>0,'requestId'=>str_repeat('c',48),'fields'=>['state'=>'delivered','receiver'=>'Teslim Alan','eventDate'=>'2026-11-03','eventTime'=>'14:30','serialNumbers'=>'SKU / SERI123','notes'=>'Kontrol edildi']];
try{
 $saved=$dossiers->save($admin,1,$target,$input,['bytes'=>$photo,'name'=>'teslimat.png']);check($saved['version']===1&&count($saved['files'])===1,'Belge ve alanlar birlikte kaydolur');
 $key=$saved['files'][0]['key'];$file=$dossiers->file($admin,1,$returnTarget,$key);check($file['mime']==='image/jpeg'&&$file['width']===30,'İade ekranında aynı belgenin güvenli okunması');
 $stored=$db->one('SELECT payload FROM operation_dossiers WHERE company_id=1')['payload'];check(!str_contains($stored,'Teslim Alan')&&!str_contains(file_get_contents($folder.'/'.$key.'.bin'),'JFIF'),'Not ve dosya şifreli saklanır');
 $duplicate=$dossiers->save($admin,1,$target,$input,['bytes'=>$photo,'name'=>'teslimat.png']);check($duplicate['version']===1&&count(glob($folder.'/*.bin'))===1,'Yeniden deneme belgeyi ve dosyayı çoğaltmaz');
 denied(fn()=>$dossiers->save($admin,1,$target,[...$input,'requestId'=>str_repeat('d',48)]),409);denied(fn()=>$dossiers->save($client,1,$target,$input),403);denied(fn()=>$dossiers->file($admin,2,$foreign,$key),404);
 $input['version']=1;$input['requestId']=str_repeat('e',48);$input['fields']['eventDate']='2026-02-31';denied(fn()=>$dossiers->save($admin,1,$target,$input),400);
 check($db->all('SELECT * FROM shipment_entries')===$original,'Asıl sevkiyat ve durum renkleri değişmez');
 $dbrow=$db->one('SELECT * FROM operation_dossiers WHERE company_id=1');check(BackupFiles::references('operation_dossiers',$dbrow,$cipher)===['operation-documents/'.$key.'.bin'],'Dosya yedeğe dahil edilir');
 $sources=new AttentionSources($db,$cipher);$center=new AttentionCenter($db,$sources);$pending=$center->listing($admin,1,['type'=>'shipments']);check(count($pending['items'])===1,'Eski sevk bekleyişi bulunur');$issue=$pending['items'][0];
 $decision=['key'=>$issue['key'],'fingerprint'=>$issue['fingerprint'],'type'=>'shipments','version'=>0,'status'=>'resolved','assigneeId'=>2,'note'=>'Kontrol edildi'];$center->save($admin,1,$decision);check($center->listing($admin,1,['type'=>'shipments'])['total']===0,'Tamamlanan konu bekleyenlerden çıkar');check($center->listing($admin,2,['type'=>'shipments'])['total']===1,'Diğer mağazanın aynı konusu etkilenmez');
 denied(fn()=>$center->save($admin,1,$decision),409);denied(fn()=>$center->listing($client,1,[]),403);
 $db->run("UPDATE shipment_entries SET ordered_at=? WHERE id=1",[gmdate('Y-m-d H:i:s',time()-14*86400)]);check($center->listing($admin,1,['type'=>'shipments'])['total']===1,'Kaynak değiştiğinde konu yeniden açılır');
 $security=new Security($db,[]);$routes=new OperationRoutes($db,$security,$cipher,$folder);foreach(['/api/operations/attention','/api/operations/dossier','/api/operations/file'] as $route)denied(fn()=>$routes->handle($route,'GET',$client),403);
 $gate=new MarketplaceGate($db);check(!$gate->status($admin,1)['configured'],'İlk şifreyi sistem kendisi belirlemez');denied(fn()=>$gate->require($admin,1),423);
 $firstGrant=$gate->unlock($admin,1,['setup'=>true,'password'=>'Example-Test-Secret-2030','confirmation'=>'Example-Test-Secret-2030']);$_SERVER['HTTP_X_OMNIGO_MANAGEMENT_GRANT']=$firstGrant['grant'];$gate->require($admin,1);$gate->require($admin,2);denied(fn()=>$gate->require($other,1),423);
 denied(fn()=>$gate->unlock($other,1,['password'=>'Wrong']),403);$otherGrant=$gate->unlock($other,1,['password'=>'Example-Test-Secret-2030']);$_SERVER['HTTP_X_OMNIGO_MANAGEMENT_GRANT']=$otherGrant['grant'];$gate->require($other,1);denied(fn()=>$gate->status($client,1),403);
 $_SERVER['HTTP_X_OMNIGO_MANAGEMENT_GRANT']=$firstGrant['grant'];$db->run('UPDATE marketplace_admin_grants SET expires=?',[time()+3600]);$gate->require($admin,1);check($gate->status($admin,1)['granted'],'On dakika sınırı yok; mevcut oturum geçerli');
 $db->run('DELETE FROM sessions WHERE token=?',[$admin['token']]);denied(fn()=>$gate->require($admin,1),423);denied(fn()=>$gate->unlock($admin,1,['password'=>'Example-Test-Secret-2030']),401);
 foreach(['/api/management/marketplaces','/api/management/marketplaces-bulk','/api/integrations/hepsiburada','/api/integrations/n11/credentials'] as $path)check(MarketplaceGate::protects($path),'Ek şifre API sınırında korunur');check(!MarketplaceGate::protects('/api/integrations/health'),'Canlı sağlık görünümü ek şifreye bağlanmaz');
 check(!str_contains($db->one('SELECT password_hash FROM marketplace_admin_lock')['password_hash'],'Example'),'Ortak şifre yalnız karma olarak tutulur');
 $legacy=['deliveryCities'=>Omnigo\CustomerCities::DEFAULTS,'activeDeliveryCities'=>Omnigo\CustomerCities::DEFAULTS];
 check(Omnigo\CustomerShippingScope::read($legacy)['mode']==='regional','Eski Vizyona düzeni bölgesel kalır');
 $nationwide=Omnigo\CustomerShippingScope::apply(['deliveryCities'=>[],'activeDeliveryCities'=>[]],['mode'=>'nationwide','regionalCities'=>[]]);
 check(count(Omnigo\CustomerCities::fromProfile($nationwide))===81,'Ulusal kapsam 81 il olur');
 $regional=Omnigo\CustomerShippingScope::apply($nationwide,['mode'=>'regional','regionalCities'=>['Ankara','İstanbul']]);
 check(Omnigo\CustomerCities::fromProfile($regional)===['Ankara','İstanbul'],'Kapsam daralması ekrandaki illere uygulanır');
 check(count(Omnigo\CustomerCities::fromProfile($regional,true))===81,'Önceden aktarılan kayıtların durum takibi kesilmez');
 denied(fn()=>Omnigo\CustomerShippingScope::validate(['mode'=>'regional','regionalCities'=>[]]),400);
 $new=Omnigo\CustomerOnboarding::create($db,$admin,'Yeni ulusal müşteri',null,null,[],null,null,['mode'=>'nationwide','regionalCities'=>[]]);
 check(count(Omnigo\CustomerCities::read($db,$new))===81,'Yeni müşteri kendi kapsamıyla kurulur');
 check(Omnigo\CustomerCities::read($db,1)===Omnigo\CustomerCities::DEFAULTS,'Başka müşterinin kapsamı etkilenmez');
 echo "Operasyon merkezi ve sevkiyat kapsamı: yönetici sınırı, mağaza ayrımı, ortak sipariş dosyası, şifreli belge, sürüm/tekrar koruması, kaynak verileri koruma, dosya yedeği, konu yeniden açma, ek şifre/oturum sınırı, bölgesel/81 il kurulumu ve geçmiş takibi geçti.\n";
}finally{foreach(glob($folder.'/*.bin')?:[] as $file)unlink($file);if(is_dir($folder))rmdir($folder);}

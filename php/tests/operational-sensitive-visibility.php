<?php
declare(strict_types=1);

use Omnigo\{CustomerPermissionCatalog, CustomerPermissionRoutes, CustomerPermissions, CustomerSensitiveVisibility, CustomerTableVisibility, Database, DeliveryCipher, HttpError,
    InventoryCatalog, InventoryHistory, InventorySchema, InventoryStatistics, ShipmentDocumentQueue, ShipmentDocuments,
    ShipmentOrdering, ShipmentRepository, ShipmentSchema, ShipmentStatusConfirmation};

$bootstrap=$argv[1]??dirname(__DIR__).'/bootstrap.php';
require $bootstrap;
spl_autoload_register(static function(string $class): void {
    if(!str_starts_with($class,'Omnigo\\'))return;
    $source=dirname(__DIR__).'/src/'.substr($class,7).'.php';
    if(is_file($source))require_once $source;
},true,true);

function check(bool $success,string $reason): void
{
    if(!$success)throw new RuntimeException($reason);
}

function reject(callable $operation,int $code): void
{
    try{$operation();}catch(HttpError $error){check($error->getCode()===$code,$error->getMessage());return;}
    throw new RuntimeException('İşlem reddedilmeliydi.');
}

$db=new Database(['dsn'=>'sqlite::memory:']);
$db->pdo->exec(file_get_contents(dirname($bootstrap).'/tests/schema.sqlite.sql'));
ShipmentSchema::install($db);
InventorySchema::install($db);
$db->run("INSERT INTO companies VALUES(1,'Örnek Mağaza'),(2,'Diğer Mağaza')");
$client=['id'=>2,'role'=>'client','company_id'=>1];
$admin=['id'=>1,'role'=>'admin'];
$cipher=DeliveryCipher::withKey(str_repeat('t',32));
$scope=['all','n11','2026-11'];
$folder=sys_get_temp_dir().'/omnigo-operational-visibility-'.bin2hex(random_bytes(8));
mkdir($folder);
$documentFiles=[];
$add=static function(string $order,array $extra=[],string $status='',int $company=1,string $day='12',array $documents=[])use($db,$cipher,$folder,&$documentFiles):int{
    $date='2026-11-'.$day.'T10:00:00+00:00';
    $payload=['orderNumber'=>$order,'orderedAt'=>$date,'productName'=>'Ürün '.$order,'quantity'=>2,'marketplaceShipping'=>'pending',...$extra];
    $db->run('INSERT INTO shipment_entries (company_id,city,provider,month,payload,ordered_at,row_status,cancelled,created,updated) VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$company,'Ankara','n11','2026-11','',ShipmentOrdering::timestamp($payload),$status,(int)($status==='cancelled'),$date,$date]);
    $id=$db->insertId();
    foreach($documents as $kind){
        $key=bin2hex(random_bytes(24));
        $file=$folder.'/'.$key.'.bin';
        file_put_contents($file,$cipher->seal($company,'shipment-document:'.$id.':'.$kind.':'.$key,['bytes'=>base64_encode('%PDF-1.7 fixture')]));
        $documentFiles[]=$file;
        $payload['documents'][$kind]=['key'=>$key,'mime'=>'application/pdf','width'=>0,'height'=>0];
    }
    $db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$cipher->seal($company,'shipment:'.$id,$payload),$id]);
    return $id;
};

try{
    $active=$add('A',day:'11',documents:['invoice','delivery']);
    $partial=$add('P',['marketplaceReturn'=>['returnedQuantity'=>1,'partial'=>true]]);
    $full=$add('R',['marketplaceReturn'=>['returnedQuantity'=>2,'partial'=>false]],documents:['invoice','delivery']);
    $cancelled=$add('C',status:'cancelled',documents:['invoice','delivery']);
    $undelivered=$add('U',['marketplaceShipping'=>'undelivered']);
    $other=$add('T',company:2,documents:['invoice','delivery']);

    $default=CustomerSensitiveVisibility::flags($db,$client,1,'shipments');
    check($default===CustomerTableVisibility::NONE,'İptal ve iadeler varsayılan olarak gizlenmeli.');
    $repo=new ShipmentRepository($db,$cipher,!$default['cancellations'],!$default['returns']);
    $listing=$repo->listing(1,$scope,filterValues:['dateFrom'=>'2026-11-12']);
    check($listing['totalRecords']===2&&$listing['total']===1&&array_column($listing['items'],'id')===[$partial],
        'Tam iade ve iptal arama, filtre ve sayaçtan önce çıkarılmalı.');
    $export=CustomerSensitiveVisibility::redact($repo->export(1,$scope),$default);
    check(array_column($export['items'],'id')===[$partial,$active]&&!str_contains(json_encode($export,JSON_THROW_ON_ERROR),'marketplaceReturn'),
        'Dışa aktarımda yalnız görülebilen satırlar bulunmalı ve kısmi iade ayrıntısı gizlenmeli.');

    $grants=CustomerPermissionCatalog::defaults();
    $grants['shipments']['viewCancelledRecords']=true;
    $grants['shipmentInvoices']['view']=true;
    $grants['shipmentInvoices']['viewCancelledRecords']=true;
    $grants['cityProducts']['view']=true;
    $grants['cityProducts']['viewReturnedRecords']=true;
    $grants['shipmentDeliveryDocuments']=['view'=>true,'documents'=>true];
    $grants['shipmentDeliveryDocuments']['viewReturnedRecords']=true;
    $grants['inventory']['viewReturnedRecords']=true;
    $restrictedGrants=CustomerPermissionCatalog::defaults();
    $restrictedGrants['shipmentInvoices']['view']=true;
    $restrictedGrants['shipmentInvoices']['documents']=true;
    $restrictedGrants['shipmentInvoices']['viewCancelledRecords']=false;
    $policy=['users'=>[2=>$grants,3=>$restrictedGrants],'pages'=>['inventory'=>'shown','shipmentInvoices'=>'shown']];
    $db->run('INSERT INTO company_profiles (company_id,payload,version) VALUES (?,?,1)',[1,json_encode(['workspacePolicy'=>$policy],JSON_THROW_ON_ERROR)]);
    $flags=CustomerSensitiveVisibility::flags($db,$client,1,'shipments');
    $invoiceFlags=CustomerSensitiveVisibility::flags($db,$client,1,'shipmentInvoices');
    $deliveryFlags=CustomerSensitiveVisibility::flags($db,$client,1,'cityProducts');
    check($flags['cancellations']&&!$flags['returns']&&$invoiceFlags['cancellations']&&!$invoiceFlags['returns']
        &&!$deliveryFlags['cancellations']&&$deliveryFlags['returns'],'Ek yetkiler tabloya özgü olmalı.');
    $repo=new ShipmentRepository($db,$cipher,!$flags['cancellations'],!$flags['returns']);
    $visible=$repo->listing(1,$scope)['items'];
    check(array_column($visible,'id')===[$undelivered,$cancelled,$partial,$active]&&!empty($visible[1]['customerReadOnly']),
        'İptal yetkisi yalnız iptal satırlarını açmalı ve bunları salt okunur bırakmalı.');
    reject(fn()=>$repo->toggle($client,1,$cancelled,$scope,['field'=>'invoiced','value'=>true,'version'=>1]),404);
    reject(fn()=>(new ShipmentStatusConfirmation($db,$cipher))->preview($client,1,$scope,['value'=>'completed','ids'=>[$full]]),404);

    $invoices=new ShipmentDocumentQueue($db,$cipher,!$invoiceFlags['cancellations'],!$invoiceFlags['returns']);
    check(array_column($invoices->page(1,'invoice','completed',0,10)['items'],'id')===[$cancelled,$active],
        'Fatura listesinde yalnız fatura yetkisi uygulanmalı.');
    $docs=new ShipmentDocuments($db,$cipher,$folder);
    check($docs->load($client,1,$cancelled,'invoice',$scope)['mime']==='application/pdf'
        &&$docs->load($client,1,$full,'delivery',$scope)['mime']==='application/pdf','Belge önizlemesi ilgili tablonun yetkisini kullanmalı.');
    reject(fn()=>$docs->load($client,1,$full,'invoice',$scope),404);
    reject(fn()=>$docs->load($client,1,$cancelled,'delivery',$scope),404);
    reject(fn()=>$docs->load($client,1,$other,'delivery',$scope),404);
    reject(fn()=>$docs->load($client,2,$other,'delivery',$scope),403);
    reject(fn()=>$docs->save($client,1,$cancelled,'invoice',$scope,1,bin2hex(random_bytes(24)),'fixture'),404);
    check($docs->load($admin,1,$full,'invoice',$scope)['mime']==='application/pdf','Omnigo belge erişimi korunmalı.');

    $restrictedClient=['id'=>3,'role'=>'client','company_id'=>1];
    $permissions=new CustomerPermissions($db,1);
    CustomerPermissionRoutes::shipment($permissions,$restrictedClient,'/api/shipments/'.$active.'/documents/invoice','GET');
    check($docs->load($restrictedClient,1,$active,'invoice',$scope)['mime']==='application/pdf','Etkin siparişin belgesi açılmalı.');
    $legacyStates=[
        ['cancelledQuantity'=>1,'partial'=>false],
        ['quantity'=>'2','cancelledQuantity'=>2],
        ['cancelledQuantity'=>'2','partial'=>'0'],
    ];
    foreach($legacyStates as $index=>$state){
        $id=$add('Eski-'.$index,['marketplaceCancellation'=>$state],documents:['invoice']);
        CustomerPermissionRoutes::shipment($permissions,$restrictedClient,'/api/shipments/'.$id.'/documents/invoice','GET');
        reject(fn()=>$docs->load($restrictedClient,1,$id,'invoice',$scope),404);
        reject(fn()=>$docs->save($restrictedClient,1,$id,'invoice',$scope,1,bin2hex(random_bytes(24)),'fixture'),404);
        reject(fn()=>$repo->toggle($restrictedClient,1,$id,$scope,['field'=>'invoiced','value'=>true,'version'=>1]),404);
        check($docs->load($client,1,$id,'invoice',$scope)['mime']==='application/pdf','Açık finansal izinle eski fatura okunabilmeli.');
    }
    $partialId=$add('Kısmi-iptal',['marketplaceCancellation'=>['quantity'=>'3','cancelledQuantity'=>1,'partial'=>true]],documents:['invoice']);
    check($docs->load($restrictedClient,1,$partialId,'invoice',$scope)['mime']==='application/pdf','Kısmi iptal etkin satırın belgesini kapatmamalı.');

    $catalog=new InventoryCatalog($db);
    $product=$catalog->ingest(1,'Hepsiburada',['id'=>'1','sku'=>'PUBLIC','title'=>'Örnek Stok','quantity'=>7,'saleActive'=>true]);
    $otherProduct=$catalog->ingest(2,'Hepsiburada',['id'=>'2','sku'=>'PRIVATE','title'=>'Diğer Stok','quantity'=>7,'saleActive'=>true]);
    $keys=[];
    foreach([[1,$product,'store_sale',-5],[1,$product,'store_return',2],[1,$product,'order_restore',1],[2,$otherProduct,'store_return',2]] as [$company,$sku,$kind,$delta]){
        $key=hash('sha256',$company.':'.$kind);
        $keys[$company.':'.$kind]=$key;
        $db->run('INSERT INTO inventory_ledger (company_id,event_key,product_key,kind,delta,quantity_after,request_hash,note,created) VALUES (?,?,?,?,?,?,?,?,?)',
            [$company,$key,$sku,$kind,$delta,7,$key,'','2026-11-12T10:00:00']);
    }
    $inventory=CustomerSensitiveVisibility::flags($db,$client,1,'inventory');
    $history=new InventoryHistory($db);
    $kinds=array_column($history->listing(1,$product,visibility:$inventory)['items'],'kind');
    check(count($kinds)===2&&in_array('store_return',$kinds,true)&&!in_array('order_restore',$kinds,true),'Yalnız açıkça yetki verilen stok hareketleri görünmeli.');
    check(array_column($history->listing(1,$product,visibility:CustomerTableVisibility::NONE)['items'],'kind')===['store_sale'],'İade varsayılan stok hareketlerine girmemeli.');
    foreach([$keys['1:order_restore'],$keys['2:store_return']] as $cursor){
        try{$history->listing(1,$product,before:$cursor,visibility:$inventory);throw new RuntimeException('Gizli hareket sayfa konumu kabul edildi.');}
        catch(InvalidArgumentException $error){check($error->getMessage()==='Hareket sayfası geçersiz.','Kayıt kapsamı sayfalamada korunmalı.');}
    }
    $stats=new InventoryStatistics($db);
    check($stats->storeSalesForKeys(1,[$product],false)[$product]===5&&$stats->storeSalesForKeys(1,[$product],true)[$product]===3,
        'İade yetkisi kapalıyken mağaza satış adedinden iade çıkarılmamalı.');
    echo "Operasyon listeleri, dışa aktarım, belge yetkileri ve stok geçmişi doğrulandı.\n";
}finally{
    foreach($documentFiles as $file)unlink($file);
    rmdir($folder);
}

<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{InventorySaleStatus,InventoryCatalogPage};
$checks=0;
function same(mixed $expected,mixed $actual):void{global $checks;if($expected!==$actual)throw new RuntimeException('Satış durumu beklenenle eşleşmedi.');$checks++;}
same(true,InventorySaleStatus::fromSource('hepsiburada',['isSalable'=>true,'availableStock'=>6]));
same(false,InventorySaleStatus::fromSource('hepsiburada',['isSalable'=>false,'availableStock'=>6]));
foreach(['isSuspended','isLocked','isFrozen'] as $field)same(false,InventorySaleStatus::fromSource('hepsiburada',['isSalable'=>true,$field=>true]));
same(false,InventorySaleStatus::fromSource('hepsiburada',['isSalable'=>true,'deactivationReasons'=>['blocked']]));
same(null,InventorySaleStatus::fromSource('hepsiburada',['availableStock'=>6]));
same(true,InventorySaleStatus::fromSource('n11',['status'=>'Active','saleStatus'=>'On_Sale']));
same(false,InventorySaleStatus::fromSource('n11',['status'=>'Active','saleStatus'=>'Off_Sale']));
same(false,InventorySaleStatus::fromSource('n11',['status'=>'Suspended','saleStatus'=>'On_Sale']));
same(null,InventorySaleStatus::fromSource('n11',['status'=>'Active','quantity'=>6]));
same(true,InventorySaleStatus::fromSource('koctas',['active'=>true]));
same(false,InventorySaleStatus::fromSource('koctas',['active'=>false,'quantity'=>8]));
same(null,InventorySaleStatus::fromSource('koctas',['quantity'=>8]));
foreach([0=>true,1=>true,2=>false,3=>false] as $partition=>$expected)same($expected,InventorySaleStatus::fromSource('pttavm',[],$partition));
same(null,InventorySaleStatus::fromSource('pttavm',[]));
same(null,InventorySaleStatus::fromSource('pazarama',['Approved'=>true,'productStatus'=>1,'stockCount'=>8]));
same(null,InventorySaleStatus::fromSource('idefix',['status'=>'ready_for_sale','matchedProduct'=>['id'=>1],'inventoryQuantity'=>8]));
$page=InventoryCatalogPage::normalize('pttavm',[['urunId'=>4,'urunAdi'=>'Ürün','variantListesi'=>[['variantBarkod'=>'VAR1','miktar'=>4]]]],0,2);
same(false,$page['items'][0]['saleActive']);
same(4,$page['items'][0]['quantity']);
echo "$checks kaynak satış durumu kontrolü geçti.\n";

<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{Database,InventorySchema,InventoryCatalog,InventorySettings,InventoryProgress,InventoryRead,InventoryStock,InventoryCommands,MarketplaceStockPolicy};
$db=new Database(['dsn'=>'sqlite::memory:']);$db->pdo->exec(file_get_contents(__DIR__.'/schema.sqlite.sql'));InventorySchema::install($db);
$checks=0;function verify(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$catalog=new InventoryCatalog($db);$read=new InventoryRead($db);$stock=new InventoryStock($db);$policy=new MarketplaceStockPolicy($db);
$models=[1=>['mode'=>'horoz'],2=>['mode'=>'both'],3=>['mode'=>'store'],4=>['mode'=>'marketplace'],5=>['mode'=>'horoz','marketplaceManagement'=>true]];$keys=[];
foreach($models as $id=>$settings){
 $db->run('INSERT INTO companies VALUES(?,?)',[$id,'Örnek mağaza '.$id]);$db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$id,json_encode(['workspacePolicy'=>['inventory'=>$settings]])]);
 (new InventoryProgress($db))->ensure($id,'hepsiburada');$db->run('UPDATE inventory_catalog_jobs SET last_success=? WHERE company_id=?',[gmdate('c'),$id]);
 $key=$keys[$id]=$catalog->ingest($id,'Hepsiburada',['id'=>'H1','sku'=>'MODEL-1','title'=>'Ortak kodlu örnek ürün','quantity'=>10,'saleActive'=>true]);
 verify($policy->option($id)!==null,'Beşinci seçenek tüm müşterilerde sunulur.');verify($policy->enabled($id)===($id===5),'Yeni modeli yalnız açıkça seçilmiş müşteri kullanır.');
 if($id!==4){$item=$read->listing($id,'',0,'all')['items'][0];$stock->change($id,$key,'five-model-initialize-'.$id,1,'set',3,1,'Örnek sayım',null,$item['sourceVersion']??null);}
}
foreach($models as $id=>$settings){
 $item=$read->listing($id,'',0,'all')['items'][0];$expected=match($id){1,3,5=>3,2=>13,4=>10};verify($item['totalQuantity']===$expected,'Model '.$id.' ilk stok hesabı doğru.');
 if($id!==4){$stock->change($id,$keys[$id],'five-model-sale-000'.$id,$item['version'],'store_sale',1,1,'Mağaza satışı',null,$item['sourceVersion']??null);$again=$read->listing($id,'',0,'all')['items'][0];verify($again['totalQuantity']===$expected-1,'Model '.$id.' mağaza satışı yalnız kendi havuzunu azaltır.');}
 else{try{(new InventoryCommands($db))->submit($id,['requestId'=>'five-model-denied-004','productKey'=>$keys[$id],'version'=>$item['version'],'mode'=>'store_sale','amount'=>1],1);throw new RuntimeException('Yalnız canlı stok modelinde mağaza satışı açıldı.');}catch(DomainException){verify(true,'Yalnız canlı modelde manuel mağaza satışı kapalı.');}}
 $catalog->ingest($id,'Hepsiburada',['id'=>'H1','sku'=>'MODEL-1','title'=>'Ortak kodlu örnek ürün','quantity'=>7,'saleActive'=>true]);$fresh=$read->listing($id,'',0,'all')['items'][0];$expected=match($id){1,5=>-1,2=>9,3=>2,4=>7};verify($fresh['totalQuantity']===$expected,'Model '.$id.' canlı güncellemede manuel fark / ayrı havuz korunur.');
 if($id!==4){$stock->change($id,$keys[$id],'five-model-return-00'.$id,$fresh['version'],'store_return',1,1,'Satış iadesi',null,$fresh['sourceVersion']??null);verify($read->listing($id,'',0,'all')['items'][0]['totalQuantity']===$expected+1,'Model '.$id.' iade adedini kendi havuzuna ekler.');}
}
$before=$db->one('SELECT payload FROM company_profiles WHERE company_id=1')['payload'];$policy->option(1);verify($db->one('SELECT payload FROM company_profiles WHERE company_id=1')['payload']===$before,'Seçeneğin görünmesi eski müşterinin modelini değiştirmez.');
verify($models[1]===InventorySettings::validate(['mode'=>'horoz']),'Vizyona benzeri eski canlı + manuel ayar biçimi aynen korunur.');
echo "Beş stok modeli: $checks hesaplama, satış/iade, negatif fark, canlı güncelleme, seçilebilirlik ve mağaza ayrımı kontrolü geçti.\n";

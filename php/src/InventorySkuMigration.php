<?php
declare(strict_types=1);
namespace Omnigo;

/** Reassign catalogue listings while retaining the original stock and order records. */
final class InventorySkuMigration
{
    public function __construct(private Database $db){}

    public function run(int $company,bool $preview=false,?callable $backup=null,bool $refresh=false):array
    {
        $change=function()use($company,$preview,$backup,$refresh):array{
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.($preview?'':$this->db->lock()),[$company]))
                throw new \DomainException('Mağaza bulunamadı.');
            $profileRow=$this->db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);
            $profile=$profileRow?json_decode($profileRow['payload'],true,64,JSON_THROW_ON_ERROR):[];
            if(($profile['workspacePolicy']['inventory']['mode']??'')!=='horoz')
                throw new \DomainException('Mağaza ortak canlı stok modelinde değil.');
            if(!$refresh&&($profile[InventoryLiveSkuIdentity::POLICY]['version']??null)===1)return ['active'=>true,'applied'=>false];
            if($this->db->one("SELECT request_id FROM inventory_commands WHERE company_id=? AND state='pending' LIMIT 1",[$company]))
                throw new \DomainException('Stok işlemleri tamamlandıktan sonra tekrar deneyin.');
            $products=array_column($this->db->all('SELECT * FROM inventory_products WHERE company_id=? ORDER BY product_key',[$company]),null,'product_key');
            $listings=$this->db->all("SELECT * FROM inventory_listings WHERE company_id=? AND remote_id NOT LIKE 'order-sku:%' ORDER BY provider,listing_key",[$company]);
            [$targets,$codes,$summary,$primaryCodes,$assignments,$conflicts]=$this->plan($company,$products,$listings);
            if($preview)return $summary;

            if($backup)$backup(['company'=>$company,'created'=>gmdate('c'),'products'=>array_values($products),'listings'=>$listings,
                'policies'=>array_intersect_key($profile,array_flip([InventoryBarcodeIdentity::POLICY,InventoryLiveSkuIdentity::POLICY]))]);
            $catalog=new InventoryCatalog($this->db);
            $changed=[];
            foreach($listings as $i=>$row){
                $sku=$codes[$i];$old=$row['product_key'];
                $confirmed=$assignments[$i]!==null;
                $key=$assignments[$i]??InventoryBarcodeIdentity::pendingKey($row['provider'],$row['listing_key']);
                $evidence=json_decode($row['evidence'],true,32,JSON_THROW_ON_ERROR);
                $evidence['identityVerified']=$confirmed;
                $evidence['skuConfirmed']=(int)$confirmed;
                unset($evidence['barcodeConfirmed']);
                if($confirmed&&!isset($products[$key])){
                    $barcode=(string)($evidence['barcode']??'');
                    $primary=$primaryCodes[$key]??$sku;
                    $title=$evidence['sourceTitle']??$evidence['metadataTitle']??'Stok kodu: '.$primary;
                    $catalog->saveProduct($company,$key,$title,$primary,InventoryCatalog::validGtin($barcode)?$barcode:'',$row['observed_at'],false);
                    $products[$key]=true;
                }
                $this->db->run('UPDATE inventory_listings SET product_key=?,evidence=? WHERE company_id=? AND provider=? AND listing_key=?',
                    [$key,json_encode($evidence,JSON_THROW_ON_ERROR),$company,$row['provider'],$row['listing_key']]);
                if($key!==$old){$changed[$key]=true;$changed[$old]=true;}
            }
            foreach(array_keys($changed) as $key)
                $this->db->run('UPDATE inventory_products SET version=version+1 WHERE company_id=? AND product_key=?',[$company,$key]);
            $keys=array_filter($targets,static fn(string $key,string $skuKey):bool=>$key!==$skuKey,ARRAY_FILTER_USE_BOTH);
            $profile[InventoryLiveSkuIdentity::POLICY]=['version'=>1,'keys'=>$keys,'conflicts'=>$conflicts];
            unset($profile[InventoryBarcodeIdentity::POLICY]);
            $this->db->run('UPDATE company_profiles SET payload=?,version=version+1 WHERE company_id=?',
                [json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$company]);
            $count=$this->db->one("SELECT COUNT(*) n,COALESCE(SUM(source_quantity),0) quantity FROM inventory_listings WHERE company_id=? AND remote_id NOT LIKE 'order-sku:%'",[$company]);
            $matched=$this->db->one('SELECT COUNT(DISTINCT l.product_key) n FROM inventory_listings l WHERE l.company_id=? AND '.InventoryLiveSkuIdentity::confirmedSql(),[$company]);
            if((int)$count['n']!==$summary['listings']||(int)$count['quantity']!==$summary['sourceQuantity']||(int)$matched['n']!==$summary['stockCodes'])
                throw new \RuntimeException('Stok kodu geçişinde kaynak kayıtları doğrulanamadı.');
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(NULL,?,?,NULL,?,?)',
                [$company,'Canlı stokta stok koduyla eşleştirme etkinleştirildi',json_encode($summary,JSON_THROW_ON_ERROR),gmdate('c')]);
            return [...$summary,'active'=>true,'applied'=>true];
        };
        return $preview||$this->db->pdo->inTransaction()?$change():$this->db->transaction($change);
    }

    private function plan(int $company,array $products,array $listings):array
    {
        $groups=$codes=$sources=$facts=$saleHistory=$protected=$activeTargets=$linkedSources=[];
        foreach($listings as $i=>$row){
            $sku=$codes[$i]=InventoryLiveSkuIdentity::listingSku($row['provider'],$row['remote_id'],$row['sku']);
            if($sku!==''){
                $skuKey=InventoryLiveSkuIdentity::skuKey($sku);
                $sources[$row['product_key']][$row['provider']][$skuKey]=true;
                $facts[$skuKey]??=['sku'=>InventoryLiveSkuIdentity::normalize($sku),'barcodes'=>[],'names'=>[]];
                $evidence=json_decode($row['evidence'],true,32,JSON_THROW_ON_ERROR);
                $barcode=InventoryProductMatch::barcode((string)($evidence['barcode']??''));
                $old=$products[$row['product_key']]??[];
                $title=$evidence['sourceTitle']??$evidence['metadataTitle']??'';
                if($title===''&&$barcode!==''&&InventoryProductMatch::barcode($old['barcode']??'')===$barcode)$title=$old['title'];
                $name=InventoryProductMatch::name($title);
                if($name!=='')$facts[$skuKey]['names'][$name]=true;
                if($barcode!=='')$facts[$skuKey]['barcodes'][$barcode]=true;
            }
        }
        $conflicts=array_fill_keys(array_keys(InventoryProductMatch::conflicts($facts)),true);
        $cleanFacts=array_diff_key($facts,$conflicts);
        $aliases=InventoryProductMatch::aliases($cleanFacts);
        foreach(array_keys($cleanFacts) as $skuKey)$groups[$aliases[$skuKey]??$skuKey]=[];
        foreach(['inventory_shared_stock','inventory_purchase_prices','inventory_removed_products','inventory_manual_products','inventory_ledger'] as $table)
            foreach($this->db->all("SELECT DISTINCT product_key FROM $table WHERE company_id=?",[$company]) as $row)$protected[$row['product_key']]=true;
        foreach($this->db->all('SELECT DISTINCT product_key FROM inventory_balances WHERE company_id=?',[$company]) as $row)$saleHistory[$row['product_key']]=true;
        foreach($this->db->all("SELECT * FROM inventory_links WHERE company_id=? AND state IN ('pending','active')",[$company]) as $link){
            $target=$link['product_key'];$source=$link['source_product'];$provider=$link['provider'];
            if($link['state']==='pending')throw new \DomainException('Bekleyen pazaryeri eşleştirmesi tamamlandıktan sonra tekrar deneyin.');
            $skuKey=InventoryLiveSkuIdentity::skuKey($products[$target]['sku']??'');
            $targetCode=$aliases[$skuKey]??$skuKey;
            $sourceSkus=array_keys($sources[$source][$provider]??[]);
            if(array_diff(array_map(static fn(string $key):string=>$aliases[$key]??$key,$sourceSkus),[$targetCode]))
                throw new \DomainException('Farklı stok kodlarına bağlı bir pazaryeri eşleştirmesi doğrulama bekliyor.');
            $activeTargets[$target]=true;
            if($source!==$target)$linkedSources[$source]=true;
        }
        $protectedBySku=[];
        foreach($products as $key=>$product){
            if($product['quantity']!==null)$protected[$key]=true;
            $rawSku=InventoryLiveSkuIdentity::skuKey($product['sku']);
            if(isset($conflicts[$rawSku]))continue;
            $skuKey=$aliases[$rawSku]??$rawSku;
            if(isset($protected[$key])&&trim($product['sku'])!==''){
                $groups[$skuKey]??=[];
                $protectedBySku[$skuKey][$key]=true;
            }
            if(isset($groups[$skuKey])&&!isset($linkedSources[$key]))
                $groups[$skuKey][$key]=isset($activeTargets[$key])?16:
                    (isset($protected[$key])?8:0)+(isset($saleHistory[$key])?4:0)+($rawSku===$skuKey?2:0)+(isset($sources[$key])?1:0);
        }
        $targets=$primaryCodes=[];$retiredProtected=0;
        foreach($groups as $skuKey=>$candidates){
            $active=array_intersect_key($candidates,$activeTargets);
            $historical=array_intersect_key($candidates,$protected);
            if(count($active)>1||(count($historical)>1&&!$active))
                throw new \DomainException('Aynı stok koduna ait birden fazla manuel stok kaydı doğrulama bekliyor.');
            arsort($candidates,SORT_NUMERIC);
            $target=$targets[$skuKey]=array_key_first($candidates)??$skuKey;
            if(isset($facts[$skuKey]))$primaryCodes[$target]=$facts[$skuKey]['sku'];
            $retiredProtected+=count(array_diff_key($protectedBySku[$skuKey]??[],[$target=>true]));
        }
        foreach($aliases as $alias=>$skuKey)$targets[$alias]=$targets[$skuKey];
        $assignments=[];
        foreach($listings as $i=>$row){
            $sku=$codes[$i];
            $code=InventoryLiveSkuIdentity::skuKey($sku);
            $old=$row['product_key'];
            $assignments[$i]=$sku===''?null:(isset($conflicts[$code])?(isset($products[$old])?$old:null):$targets[$code]);
        }
        $confirmed=array_filter($assignments,static fn(?string $key):bool=>$key!==null);
        return [$targets,$codes,[
            'listings'=>count($listings),'stockCodes'=>count(array_unique($confirmed)),'confirmedAliases'=>count($aliases),
            'conflictingCodes'=>count($conflicts),'pending'=>count($listings)-count($confirmed),
            'sourceQuantity'=>array_sum(array_column($listings,'source_quantity')),'legacyManualRecords'=>$retiredProtected,
        ],$primaryCodes,$assignments,$conflicts];
    }
}

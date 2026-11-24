<?php
declare(strict_types=1);
namespace Omnigo;

/** Move offers to the SKU card while keeping stock counts, prices and order history in place. */
final class InventorySkuMigration
{
    public function __construct(private Database $db){}

    public function run(int $company,bool $preview=false,?callable $backup=null):array
    {
        $change=function()use($company,$preview,$backup):array{
            if(!$preview)$this->db->one('SELECT company_id FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.($preview?'':$this->db->lock()),[$company]))
                throw new \DomainException('Mağaza bulunamadı.');
            $profileRow=$this->db->one('SELECT * FROM company_profiles WHERE company_id=?'.($preview?'':$this->db->lock()),[$company]);
            $profile=$profileRow?json_decode($profileRow['payload'],true,64,JSON_THROW_ON_ERROR):[];
            if(($profile[InventoryLiveSkuIdentity::POLICY]['version']??null)===InventoryLiveSkuIdentity::VERSION)
                return ['active'=>true,'applied'=>false];
            $products=array_column($this->db->all('SELECT * FROM inventory_products WHERE company_id=? ORDER BY product_key',[$company]),null,'product_key');
            $listings=$this->db->all('SELECT * FROM inventory_listings WHERE company_id=? ORDER BY provider,listing_key',[$company]);
            [$members,$prepared,$new,$summary]=$this->plan($company,$products,$listings);
            if($preview)return $summary;
            $this->assertIdle($company);
            if($backup)$backup(['company'=>$company,'created'=>gmdate('c'),'profile'=>$profileRow,
                'products'=>array_values($products),'listings'=>$listings,'newProductKeys'=>array_keys($new)]);

            $catalog=new InventoryCatalog($this->db);
            foreach($new as $key=>$card){
                $catalog->saveProduct($company,$key,$card['title'],$card['sku'],$card['barcode'],$card['observed'],strict:false,verifiedSku:true);
            }
            $changed=array_fill_keys([...array_keys($members),...array_values($members)],true);
            foreach($prepared as [$row,$key,$sku,$evidence]){
                $this->db->run('UPDATE inventory_listings SET product_key=?,sku=?,evidence=? WHERE company_id=? AND provider=? AND listing_key=?',
                    [$key,$sku,$evidence,$company,$row['provider'],$row['listing_key']]);
                if($key!==$row['product_key'])$changed[$key]=$changed[$row['product_key']]=true;
            }
            foreach(array_keys($changed) as $key){
                $this->db->run('UPDATE inventory_products SET version=version+1 WHERE company_id=? AND product_key=?',[$company,$key]);
            }
            $profile[InventoryLiveSkuIdentity::POLICY]=['version'=>InventoryLiveSkuIdentity::VERSION,'members'=>$members];
            unset($profile[InventoryBarcodeIdentity::POLICY]);
            $payload=json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($profileRow)$this->db->run('UPDATE company_profiles SET payload=?,version=version+1 WHERE company_id=?',[$payload,$company]);
            else $this->db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,$payload]);
            $stored=$this->db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);
            if($stored['payload']!==$payload)throw new \RuntimeException('Stok kartı eşlemeleri mağaza kaydına eksiksiz yazılamadı.');
            $after=$this->db->one('SELECT COUNT(*) n,COALESCE(SUM(source_quantity),0) quantity FROM inventory_listings WHERE company_id=?',[$company]);
            if((int)$after['n']!==count($listings)||(int)$after['quantity']!==$summary['sourceQuantity'])
                throw new \RuntimeException('Katalog ilanları veya kaynak adetleri değişti.');
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(NULL,?,?,NULL,?,?)',
                [$company,'Stok kartları mağaza içinde SKU’ya göre eşleştirildi',json_encode($summary,JSON_THROW_ON_ERROR),gmdate('c')]);
            return [...$summary,'active'=>true,'applied'=>true];
        };
        return $preview||$this->db->pdo->inTransaction()?$change():TransactionRetry::run($this->db,$change);
    }

    private function assertIdle(int $company):void
    {
        foreach([
            "SELECT request_id FROM inventory_commands WHERE company_id=? AND state='pending'",
            "SELECT product_key FROM inventory_links WHERE company_id=? AND state='pending'",
        ] as $sql)if($this->db->one($sql.' LIMIT 1',[$company]))
            throw new \DomainException('Bekleyen stok işlemleri tamamlandıktan sonra tekrar deneyin.');
        if((new MarketplaceListingTransfers($this->db))->installed()
            &&$this->db->one('SELECT request_id FROM marketplace_listing_transfers WHERE company_id=? AND done=0 LIMIT 1',[$company]))
            throw new \DomainException('Pazaryeri stok aktarımı tamamlandıktan sonra tekrar deneyin.');
        if(IntegrationImportSchema::tableAvailable($this->db,'marketplace_stock_requests','state')
            &&$this->db->one("SELECT request_id FROM marketplace_stock_requests WHERE company_id=? AND state NOT IN ('succeeded','failed') LIMIT 1",[$company]))
            throw new \DomainException('Pazaryeri stok isteği tamamlandıktan sonra tekrar deneyin.');
    }

    private function plan(int $company,array $products,array $listings):array
    {
        $groups=$codeByKey=[];
        foreach($products as $key=>$product){
            $code=InventoryLiveSkuIdentity::normalize($product['sku']);
            if($code==='')continue;
            $groups[$code][]=$key;
            $codeByKey[$key]=$code;
        }
        $observed=[];$codes=[];
        foreach($listings as $i=>$listing){
            $evidence=json_decode($listing['evidence'],true,32,JSON_THROW_ON_ERROR);
            $sku=$codes[$i]=!empty($evidence['sellerSkuCaptured'])?trim($listing['sku'])
                :InventoryLiveSkuIdentity::listingSku($listing['provider'],$listing['remote_id'],$listing['sku']);
            if($sku==='')continue;
            $code=InventoryLiveSkuIdentity::normalize($sku);
            $groups[$code]??=[];
            if(($codeByKey[$listing['product_key']]??null)===$code&&!str_starts_with($listing['remote_id'],'order-sku:'))
                $observed[$listing['product_key']]=1+($observed[$listing['product_key']]??0);
        }
        $owned=fn(string $table)=>array_fill_keys(array_column($this->db->all("SELECT product_key FROM $table WHERE company_id=?",[$company]),'product_key'),true);
        $removed=$owned('inventory_removed_products');$manual=$owned('inventory_manual_products');$priced=$owned('inventory_purchase_prices');
        $shared=array_fill_keys(array_column($this->db->all('SELECT product_key FROM inventory_shared_stock WHERE company_id=? AND (manual_quantity IS NOT NULL OR adjustment<>0)',[$company]),'product_key'),true);
        $active=[];
        foreach($this->db->all("SELECT product_key,source_product,match_code FROM inventory_links WHERE company_id=? AND state='active'",[$company]) as $link){
            $targetCode=$codeByKey[$link['product_key']]??null;
            if($targetCode!==null&&$targetCode===($codeByKey[$link['source_product']]??null))$active[$link['product_key']]=true;
        }
        $targets=$members=[];$stockConflicts=0;
        foreach($groups as $code=>$candidates){
            $hash=InventoryLiveSkuIdentity::skuKey((string)$code);
            $operational=static fn($key)=>isset($manual[$key])||isset($shared[$key])||$products[$key]['quantity']!==null;
            $rank=static fn($key)=>[(int)!isset($removed[$key]),(int)$operational($key),
                $operational($key)?($products[$key]['updated']??''):'',(int)isset($active[$key]),(int)isset($priced[$key]),(int)!empty($products[$key]['image_url']),
                $observed[$key]??0];
            usort($candidates,static fn($a,$b)=>($rank($b)<=>$rank($a))?:strcmp($a,$b));
            $target=$targets[$code]=$candidates[0]??$hash;
            if(count(array_filter($candidates,$operational))>1)$stockConflicts++;
            foreach($candidates as $key)if($key!==$target)$members[$key]=$target;
        }

        $prepared=$new=[];$pending=0;
        foreach($listings as $i=>$row){
            $sku=$codes[$i];
            $key=$sku!==''?$targets[InventoryLiveSkuIdentity::normalize($sku)]
                :InventoryLiveSkuIdentity::pendingKey($row['provider'],$row['listing_key']);
            $evidence=json_decode($row['evidence'],true,32,JSON_THROW_ON_ERROR);
            $evidence['sourceTitle']??=$products[$row['product_key']]['title']??'';
            $evidence['skuConfirmed']=(int)($sku!=='');
            $evidence['identityVerified']=$sku!=='';
            unset($evidence['barcodeConfirmed']);
            if($sku===''&&!str_starts_with($row['remote_id'],'order-sku:'))$pending++;
            if($sku!==''&&!isset($products[$key])&&!isset($new[$key])){
                $title=trim((string)($evidence['sourceTitle']??$evidence['metadataTitle']??''));
                $barcode=(string)($evidence['barcode']??'');
                $new[$key]=['sku'=>$sku,'title'=>$title!==''?mb_substr($title,0,500):'Stok kodu: '.$sku,
                    'barcode'=>InventoryCatalog::validGtin($barcode)?$barcode:'','observed'=>$row['observed_at']];
            }
            $json=json_encode($evidence,JSON_THROW_ON_ERROR);
            if($key!==$row['product_key']||$sku!==$row['sku']||$json!==$row['evidence'])$prepared[]=[$row,$key,$sku,$json];
        }
        return [$members,$prepared,$new,[
            'listings'=>count($listings),'stockCodes'=>count($targets),'pending'=>$pending,'mergedCards'=>count($members),
            'newCards'=>count($new),'changedListings'=>count($prepared),'multipleManualRecords'=>$stockConflicts,
            'sourceQuantity'=>array_sum(array_column($listings,'source_quantity')),
        ]];
    }
}

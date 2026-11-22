<?php
declare(strict_types=1);
namespace Omnigo;

/** Apply an audited source snapshot without moving stock movements or old sales. */
final class InventoryBarcodeBackfill
{
    private const HISTORY=[
        'inventory_balances','inventory_ledger','inventory_commands','inventory_purchase_prices',
        'inventory_shared_stock','inventory_removed_products','inventory_manual_products','inventory_pending_lines',
    ];

    public function __construct(private Database $db){}

    public function apply(int $company,string $expectedName,array $sourceRows,callable $backup,bool $dryRun=false):array
    {
        return $this->db->transaction(function()use($company,$expectedName,$sourceRows,$backup,$dryRun){
            $tenant=$this->db->one('SELECT name FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if(($tenant['name']??null)!==$expectedName)throw new \DomainException('Mağaza kimliği değişti.');
            if(InventoryBarcodeIdentity::load($this->db,$company))throw new \DomainException('Barkodla aktarım zaten etkin.');
            if($this->db->one('SELECT l.product_key FROM inventory_listings l WHERE l.company_id=? AND '.InventoryBarcodeIdentity::confirmedSql().' LIMIT 1',[$company]))
                throw new \DomainException('Daha önce doğrulanan barkod eşleşmeleri zaten var.');
            if($this->db->one('SELECT product_key FROM inventory_links WHERE company_id=? LIMIT 1',[$company]))
                throw new \DomainException('Manuel pazaryeri bağlantıları önce doğrulanmalı.');
            $products=array_column($this->db->all('SELECT * FROM inventory_products WHERE company_id=?',[$company]),null,'product_key');
            $listings=$this->db->all("SELECT * FROM inventory_listings WHERE company_id=? AND remote_id NOT LIKE 'order-sku:%' ORDER BY provider,listing_key",[$company]);
            [$sources,$keys,$summary]=$this->plan($company,$sourceRows,$listings,$products);
            if($dryRun)return $summary;

            $before=['products'=>array_values($products),'listings'=>$listings];
            foreach(['inventory_aliases','inventory_image_jobs'] as $table)
                $before[$table]=$this->db->all("SELECT * FROM $table WHERE company_id=?",[$company]);
            $backup(['company'=>$company,'created'=>gmdate('c'),'before'=>$before,'keys'=>$keys]);

            $identity=new InventoryBarcodeIdentity($this->db,$company,$keys);
            foreach($listings as $listing){
                $source=$sources[$listing['provider'].':'.$listing['listing_key']]??[];
                $identity->updateStoredListing($listing,$source);
            }
            InventoryBarcodeIdentity::enable($this->db,$company,$keys);
            $catalogue=$this->db->one("SELECT COUNT(*) n,COALESCE(SUM(source_quantity),0) quantity FROM inventory_listings WHERE company_id=? AND remote_id NOT LIKE 'order-sku:%'",[$company]);
            $confirmed=$this->db->one('SELECT COUNT(DISTINCT l.product_key) n FROM inventory_listings l WHERE l.company_id=? AND '.InventoryBarcodeIdentity::confirmedSql(),[$company]);
            if((int)$catalogue['n']!==count($listings)||(int)$catalogue['quantity']!==array_sum(array_column($listings,'source_quantity'))
                ||(int)$confirmed['n']!==$summary['barcodes'])throw new \RuntimeException('Barkod veya kaynak miktarı kontrolü başarısız.');
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,NULL,NULL,?,?)',
                [$company,'Canlı stokta barkodla eşleştirme etkinleştirildi',json_encode($summary,JSON_THROW_ON_ERROR),gmdate('c')]);
            return [...$summary,'enabled'=>true];
        });
    }

    private function plan(int $company,array $sourceRows,array $listings,array $products):array
    {
        $sources=[];
        foreach($sourceRows as $source){
            $provider=$source['provider'];
            $identity=$provider.':'.hash('sha256',$source['id']);
            if(!in_array($provider,InventoryCatalog::PROVIDERS,true)||isset($sources[$identity]))
                throw new \DomainException('Kaynak ilanın kimliği doğrulanamadı.');
            $sources[$identity]=[
                'barcode'=>InventoryBarcodeIdentity::barcode($source['barcode']),
                'title'=>$source['title'],
                'sourceCatalogId'=>$source['hbSku']??'',
            ];
        }
        $barcodes=$productCodes=$perProduct=$matched=$groups=[];
        $missing=0;
        foreach($listings as $row){
            $id=$row['provider'].':'.$row['listing_key'];
            $old=$row['product_key'];
            $perProduct[$old]=($perProduct[$old]??0)+1;
            if(!isset($sources[$id]))continue;
            $matched[$id]=true;
            $barcode=$sources[$id]['barcode'];
            if($barcode===''){$missing++;continue;}
            $code=InventoryBarcodeIdentity::code($barcode);
            $barcodes[$code]=true;
            $productCodes[$old][$code]=($productCodes[$old][$code]??0)+1;
            $groups[$code][$old]=true;
        }
        if(!$sources||count($matched)!==count($sources)||!$barcodes)
            throw new \DomainException('Kaynak ve son stok taraması aynı ilanları içermiyor. Sonraki taramayı bekleyin.');

        $protected=[];
        foreach(self::HISTORY as $table)
            foreach($this->db->all("SELECT DISTINCT product_key FROM $table WHERE company_id=?",[$company]) as $row)
                $protected[$row['product_key']]=true;
        $manual=array_fill_keys(array_column($this->db->all('SELECT product_key FROM inventory_manual_products WHERE company_id=?',[$company]),'product_key'),true);
        $keys=[];
        $duplicates=0;
        foreach($groups as $code=>$linked){
            $candidates=[];
            if(count($linked)>1)$duplicates++;
            foreach(array_keys($linked) as $old){
                if(!isset($products[$old])||isset($manual[$old])||count($productCodes[$old])!==1)continue;
                $previous=InventoryBarcodeIdentity::barcode($products[$old]['barcode']);
                if($previous!==''&&InventoryBarcodeIdentity::code($previous)!==$code)continue;
                $fullyMatched=$perProduct[$old]===$productCodes[$old][$code];
                if(isset($protected[$old])&&!$fullyMatched&&$previous==='')continue;
                $candidates[$old]=isset($protected[$old])?2:1;
            }
            if(count(array_filter($candidates,static fn(int $score)=>$score===2))>1)continue;
            arsort($candidates);
            if($candidates)$keys[$code]=array_key_first($candidates);
        }
        return [$sources,$keys,[
            'listings'=>count($sources),'barcodes'=>count($barcodes),'pending'=>$missing,
            'duplicates'=>$duplicates,'retainedProductKeys'=>count($keys),
            'legacyWithHistory'=>count(array_diff_key($protected,array_fill_keys(array_values($keys),true),$manual)),
        ]];
    }
}

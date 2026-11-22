<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit marketplace barcodes are authoritative for stores that opt in. */
final class InventoryBarcodeIdentity
{
    public const POLICY='inventoryBarcodeIdentity';

    public function __construct(private Database $db,private int $company,private array $keys){}

    public static function load(Database $db,int $company):?self
    {
        $row=$db->one("SELECT JSON_EXTRACT(payload,'$.inventoryBarcodeIdentity') policy FROM company_profiles WHERE company_id=?",[$company]);
        if(empty($row['policy']))return null;
        $policy=json_decode($row['policy'],true,32,JSON_THROW_ON_ERROR);
        return ($policy['version']??null)===1?new self($db,$company,$policy['keys']??[]):null;
    }

    /** The caller holds the company lock inside the same database transaction. */
    public static function enable(Database $db,int $company,array $keys):void
    {
        $row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?'.$db->lock(),[$company]);
        $profile=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
        if(isset($profile[self::POLICY]))throw new \DomainException('Barkodla aktarım zaten etkin.');
        $profile[self::POLICY]=['version'=>1,'keys'=>$keys];
        $payload=json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if($row)$db->run('UPDATE company_profiles SET payload=?,version=version+1 WHERE company_id=?',[$payload,$company]);
        else $db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,$payload]);
        if(!self::load($db,$company))throw new \RuntimeException('Barkod ayarı kaydedilemedi.');
    }

    public static function barcode(mixed $value):string
    {
        if(!is_string($value))return '';
        $value=trim($value);
        return trim($value,'0')!==''&&preg_match('/^[A-Za-z0-9][A-Za-z0-9._+ \/-]{0,31}$/D',$value)?$value:'';
    }

    public static function code(string $barcode):string
    {
        return (InventoryCatalog::validGtin($barcode)?'gtin:'.str_pad($barcode,14,'0',STR_PAD_LEFT):'source:'.$barcode);
    }

    public function key(string $barcode):string
    {
        $code=self::code($barcode);
        return $this->keys[$code]??hash('sha256','catalog-barcode:'.$code);
    }

    public static function pendingKey(string $provider,string $listingKey):string
    {
        return hash('sha256','unassigned-catalog-listing:'.$provider.':'.$listingKey);
    }

    public function listingBarcode(string $provider,array $listing,array $evidence):string
    {
        $barcode=self::barcode($listing['barcode']??'');
        $sourceId=$listing['sourceCatalogId']??'';
        if($barcode===''&&$provider==='Hepsiburada'&&$sourceId!==''&&$sourceId===($evidence['sourceCatalogId']??null))
            $barcode=self::barcode($evidence['metadataBarcode']??'');
        return $barcode;
    }

    public static function confirmedSql(string $alias='l'):string
    {
        return "COALESCE(JSON_EXTRACT($alias.evidence,'$.barcodeConfirmed'),0)=1";
    }

    public function pendingListings():array
    {
        $rows=$this->db->all("SELECT l.provider,COUNT(*) n FROM inventory_listings l
            WHERE l.company_id=? AND l.remote_id NOT LIKE 'order-sku:%' AND NOT (".self::confirmedSql().")
              AND l.observed_at>=? GROUP BY l.provider ORDER BY l.provider",[$this->company,gmdate('Y-m-d\TH:i:s\Z',time()-1800)]);
        return array_map(static fn(array $row)=>['provider'=>$row['provider'],'count'=>(int)$row['n']],$rows);
    }

    public function updateStoredListing(array $row,array $metadata):void
    {
        $evidence=json_decode($row['evidence'],true,32,JSON_THROW_ON_ERROR);
        $provider=$row['provider'];
        $barcode=self::barcode($metadata['barcode']??'');
        $key=$barcode===''?self::pendingKey($provider,$row['listing_key']):$this->key($barcode);
        $sourceId=$metadata['sourceCatalogId']??($evidence['sourceCatalogId']??'');
        $evidence['catalogBarcode']=$evidence['barcode']=$barcode;
        $evidence['barcodeConfirmed']=(int)($barcode!=='');
        $evidence['identityVerified']=$barcode!=='';
        $evidence['sourceCatalogId']=$sourceId;
        if($provider==='Hepsiburada')$evidence['metadataBarcode']=$barcode;
        if(!empty($metadata['images']))$evidence['imageCandidates']=array_slice(array_values(array_unique([
            ...$metadata['images'],...($evidence['imageCandidates']??[]),
        ])),0,5);
        if(!empty($metadata['brand']))$evidence['imageBrand']=$metadata['brand'];
        if($barcode!==''){
            $catalog=new InventoryCatalog($this->db);
            $catalog->saveProduct($this->company,$key,$metadata['title']??'Stok kodu: '.$row['sku'],$row['sku'],$barcode,$row['observed_at']);
            $catalog->saveAliases($this->company,$provider,$key,[$row['remote_id'],$row['sku'],$barcode,$sourceId]);
        }
        // Metadata never advances the stock observation or changes its quantity.
        $this->db->run('UPDATE inventory_listings SET product_key=?,evidence=? WHERE company_id=? AND provider=? AND listing_key=?',
            [$key,json_encode($evidence,JSON_THROW_ON_ERROR),$this->company,$provider,$row['listing_key']]);
    }

    public function applyHepsiburadaMetadata(array $item):bool
    {
        if(($item['hbSku']??'')===''||self::barcode($item['barcode']??'')==='')return false;
        $lookup="JSON_EXTRACT(evidence,'$.sourceCatalogId')";
        if($this->db->mysql())$lookup="JSON_UNQUOTE($lookup)";
        $rows=$this->db->all("SELECT * FROM inventory_listings WHERE company_id=? AND provider='Hepsiburada' AND $lookup=?",[$this->company,$item['hbSku']]);
        foreach($rows as $row)$this->updateStoredListing($row,[...$item,'sourceCatalogId'=>$item['hbSku']]);
        return (bool)$rows;
    }

    public function resolveOrder(array $line):string
    {
        // A catalogue correction must not silently rewrite the assignment of a processed order.
        $prior=$this->db->one('SELECT product_key FROM inventory_balances WHERE company_id=? AND provider=? AND line_key=?',
            [$this->company,$line['provider'],$line['lineKey']]);
        if($prior)return $prior['product_key'];
        $rows=$this->db->all("SELECT sku,product_key,evidence FROM inventory_listings
            WHERE company_id=? AND provider=? AND sku=? AND remote_id NOT LIKE 'order-sku:%'",
            [$this->company,$line['provider'],$line['sku']]);
        $products=[];
        foreach($rows as $row){
            if($row['sku']!==$line['sku'])continue;
            $evidence=json_decode($row['evidence'],true,32,JSON_THROW_ON_ERROR);
            if(empty($evidence['barcodeConfirmed']))throw new \DomainException('Sipariş ürününün kaynak barkodu doğrulama bekliyor.');
            $products[$row['product_key']]=true;
        }
        if(count($products)!==1)throw new \DomainException('Sipariş ürününe ait tek bir kaynak barkodu doğrulanamadı.');
        return array_key_first($products);
    }
}

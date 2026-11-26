<?php
declare(strict_types=1);
namespace Omnigo;

/** Independent write outbox. Local counts, sales, purchase prices and ledger are untouched. */
final class MarketplaceStockQueue
{
    private const TERMINAL=['succeeded','failed','uncertain'];
    public function __construct(private Database $db,private MarketplaceStockPolicy $policy,private ?MarketplaceStockPilot $pilot=null){}
    private function requireAccess(int $company,array $actor):void
    {
        if($this->pilot===null){$this->policy->requireAdmin($company,$actor);return;}
        Security::admin($actor);
        if(!MarketplaceStockJob::allowed($this->db,$this->pilot,$company))throw new \DomainException('Bu mağaza için pazaryeri stok yönetimi etkin değil.');
    }
    public function targets(int $company,array $keys):array
    {
        if(!$keys||!$this->policy->enabled($company))return [];
        $in=implode(',',array_fill(0,count($keys),'?'));
        $rows=$this->db->all("SELECT product_key,listing_key,sku,source_quantity,observed_at FROM inventory_listings WHERE company_id=? AND provider='Hepsiburada' AND remote_id NOT LIKE 'order-sku:%' AND product_key IN ($in)",[$company,...$keys]);
        $pending=[];foreach($this->db->all("SELECT product_key,listing_key,request_id,state,error FROM marketplace_stock_requests WHERE company_id=? AND state NOT IN ('succeeded','failed')",[$company]) as $job)$pending[$job['listing_key']]=$job;
        $result=[];foreach($rows as $row){if($row['sku']===''||$row['source_quantity']===null)continue;$job=$pending[$row['listing_key']]??null;
            $result[$row['product_key']][]=['key'=>$row['listing_key'],'provider'=>'Hepsiburada','sku'=>$row['sku'],'quantity'=>(int)$row['source_quantity'],'observedAt'=>$row['observed_at'],'requestId'=>$job['request_id']??null,'state'=>$job['state']??null];
        }return $result;
    }
    public function submit(int $company,array $input,array $actor):array
    {
        $this->requireAccess($company,$actor);
        $request=$input['requestId']??null;$key=$input['productKey']??null;$listing=$input['listingKey']??null;$quantity=$input['quantity']??null;$source=$input['sourceQuantity']??null;
        if(!is_string($request)||!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$request)||!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!is_string($listing)||!preg_match('/^[a-f0-9]{64}$/D',$listing)||!is_int($quantity)||$quantity<0||$quantity>100000000||!is_int($source)||$source<0||($input['confirmed']??false)!==true)throw new \InvalidArgumentException('Ürün, pazaryeri, stok adedi ve gönderim onayını kontrol edin.');
        $provider='hepsiburada';
        if($this->pilot){
            $provider=array_search($input['provider']??null,MarketplaceStockJob::CHANNELS,true);
            if($provider===false||strlen($request)>54)throw new \InvalidArgumentException('Pazaryeri ve işlem kimliğini kontrol edin.');
            if($provider==='pazarama'&&!MarketplaceStockJob::pazaramaEnabled($this->db,$this->pilot,$company))throw new \DomainException('Pazarama pilotu bu mağazada açık değil.');
            $request='pilot:'.$provider.':'.$request;
        }
        $fields=[$key,$listing,$quantity,$source,(int)$actor['id']];
        $identity=null;
        if($this->pilot&&$provider==='hepsiburada'){
            $identity=$input['identityToken']??null;
            if(!is_string($identity)||!preg_match('/^[a-f0-9]{64}$/D',$identity))throw new \InvalidArgumentException('Hepsiburada ürün eşleşmesini listeden yeniden doğrulayın.');
            $fields[]=$identity;
        }
        $hash=hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR));
        return TransactionRetry::run($this->db,function()use($company,$actor,$request,$key,$listing,$quantity,$source,$hash,$provider,$identity){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);$this->requireAccess($company,$actor);
            $old=$this->db->one('SELECT * FROM marketplace_stock_requests WHERE company_id=? AND request_id=?',[$company,$request]);
            if($old){if(!hash_equals($old['request_hash'],$hash))throw new \DomainException('Bu işlem kimliği farklı bir stok gönderiminde kullanılmış.');return $this->result($old);}
            if($this->pilot){
                $clientId=explode(':',$request,3)[2];
                $ids=array_map(static fn(string $channel):string=>'pilot:'.$channel.':'.$clientId,array_keys(MarketplaceStockJob::CHANNELS));
                $slots=implode(',',array_fill(0,count($ids),'?'));
                if($this->db->one("SELECT request_id FROM marketplace_stock_requests WHERE company_id=? AND request_id IN ($slots)",[$company,...$ids]))throw new \DomainException('Bu işlem kimliği başka bir pazaryeri için kullanılmış.');
            }
            $product=$this->db->one('SELECT product_key FROM inventory_products WHERE company_id=? AND product_key=?',[$company,$key]);
            $label=MarketplaceStockJob::CHANNELS[$provider];
            $row=$this->db->one("SELECT * FROM inventory_listings WHERE company_id=? AND product_key=? AND listing_key=? AND provider=? AND remote_id NOT LIKE 'order-sku:%'",[$company,$key,$listing,$label]);
            if(!$product||!$row||$row['sku']===''||$row['source_quantity']===null)throw new \OutOfBoundsException('Seçilen ürün bu mağazanın pazaryeri ilanıyla eşleşmiyor.');
            $destination=$row['remote_id'];
            if($this->pilot){
                $target=(new ActiveMarketplaceListings($this->db))->writableTarget($company,$key,$listing);
                if(($target['provider']??null)!==$label||$target['remoteId']!==$row['remote_id']||$target['sku']!==$row['sku']){
                    throw new \DomainException('İlanın güncel satış durumu ve ürün eşleşmesi doğrulanamadı.');
                }
                if($identity!==null){
                    if(!hash_equals($target['identityToken'],$identity))throw new \DomainException('Hepsiburada ürün eşleşmesi değişti. Listeyi yenileyin.');
                    // listing_key already identifies the offer. Keep the agreed catalog SKU for all later reads.
                    $destination=$target['catalogSku'];
                }
            }
            if($this->db->one('SELECT product_key FROM inventory_removed_products WHERE company_id=? AND product_key=?',[$company,$key]))throw new \DomainException('Silinen ürüne stok gönderilemez.');
            if((int)$row['source_quantity']!==$source)throw new \DomainException('Pazaryeri stoğu değişti. Ürünü yenileyip tekrar kontrol edin.');
            if($this->db->one("SELECT request_id FROM marketplace_stock_requests WHERE company_id=? AND listing_key=? AND state NOT IN ('succeeded','failed')",[$company,$listing]))throw new \DomainException('Bu ürünün önceki stok gönderimi henüz doğrulanmadı. Hareketler bölümünden sonucu kontrol edin.');
            $connection=$this->db->one('SELECT version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
            if(!$connection)throw new \DomainException('Mağazanın pazaryeri bağlantısı bulunamadı.');
            $now=gmdate('c');
            $this->db->run("INSERT INTO marketplace_stock_requests(company_id,request_id,product_key,listing_key,sku,remote_id,quantity,source_quantity,connection_version,request_hash,state,actor_id,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,'queued',?,?,?)",[$company,$request,$key,$listing,$row['sku'],$destination,$quantity,$source,(int)$connection['version'],$hash,(int)$actor['id'],$now,$now]);
            return $this->get($company,$request,$actor);
        });
    }
    public function get(int $company,string $request,array $actor):array
    {
        Security::admin($actor);$row=$this->db->one('SELECT * FROM marketplace_stock_requests WHERE company_id=? AND request_id=?',[$company,$request]);
        if(!$row)throw new \OutOfBoundsException('Bu müşteriye ait stok gönderimi bulunamadı.');
        return $this->result($row);
    }
    /** One deterministic child per failed attempt: duplicate clicks cannot create duplicate writes. */
    public function retry(int $company,string $request,array $actor):array
    {
        if($this->pilot){
            $this->requireAccess($company,$actor);
            if(MarketplaceStockJob::origin($request)===null)throw new \DomainException('Bu işlem pilot menüsünden oluşturulmamış.');
            $this->db->run("UPDATE marketplace_stock_requests SET state=CASE WHEN receipt_id IS NULL THEN 'sending' ELSE 'accepted' END,next_run=0,attempts=0,updated=? WHERE company_id=? AND request_id=? AND state='uncertain'",[gmdate('c'),$company,$request]);
            return $this->get($company,$request,$actor);
        }
        $this->policy->requireAdmin($company,$actor);
        if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$request))throw new \InvalidArgumentException('Stok talebi geçersiz.');
        return TransactionRetry::run($this->db,function()use($company,$request,$actor){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $old=$this->db->one('SELECT * FROM marketplace_stock_requests WHERE company_id=? AND request_id=?',[$company,$request]);
            if(!$old)throw new \OutOfBoundsException('Bu müşteriye ait stok talebi bulunamadı.');
            if($old['state']==='uncertain'){
                // Unknown POST results may already be accepted remotely. Reconcile, never re-POST.
                $this->db->run('UPDATE marketplace_stock_requests SET state=?,next_run=0,attempts=0,updated=? WHERE company_id=? AND request_id=? AND state=?',[$old['receipt_id']?'accepted':'sending',gmdate('c'),$company,$request,'uncertain']);
                return $this->get($company,$request,$actor);
            }
            if($old['state']!=='failed')return $this->result($old);
            $child=hash('sha256','marketplace-stock-retry:'.$company.':'.$request);
            $existing=$this->db->one('SELECT * FROM marketplace_stock_requests WHERE company_id=? AND request_id=?',[$company,$child]);
            if($existing)return $this->result($existing);
            $row=$this->db->one("SELECT * FROM inventory_listings WHERE company_id=? AND product_key=? AND listing_key=? AND provider='Hepsiburada'",[$company,$old['product_key'],$old['listing_key']]);
            if(!$row||$row['sku']!==$old['sku']||$row['remote_id']!==$old['remote_id']||$row['source_quantity']===null)throw new \DomainException('Ürün eşleşmesi değişti; eski talep başka bir ürüne gönderilemez.');
            // Re-check the source under the same tenant lock, preserving the original desired quantity.
            return $this->insertRetry($company,$old,$row,$child,$actor);
        });
    }
    private function insertRetry(int $company,array $old,array $listing,string $request,array $actor):array
    {
        if($this->db->one('SELECT product_key FROM inventory_removed_products WHERE company_id=? AND product_key=?',[$company,$old['product_key']]))throw new \DomainException('Silinen ürüne stok gönderilemez.');
        if($this->db->one("SELECT request_id FROM marketplace_stock_requests WHERE company_id=? AND listing_key=? AND state NOT IN ('succeeded','failed')",[$company,$old['listing_key']]))throw new \DomainException('Bu ürün için bekleyen başka bir stok talebi var.');
        $connection=$this->db->one("SELECT version FROM records WHERE company_id=? AND kind='integration_hepsiburada'",[$company]);
        if(!$connection)throw new \DomainException('Pazaryeri bağlantısı bulunamadı.');
        $now=gmdate('c');$quantity=(int)$old['quantity'];$source=(int)$listing['source_quantity'];
        $hash=hash('sha256',json_encode([$old['product_key'],$old['listing_key'],$quantity,$source,(int)$actor['id']],JSON_THROW_ON_ERROR));
        $this->db->run("INSERT INTO marketplace_stock_requests(company_id,request_id,product_key,listing_key,sku,remote_id,quantity,source_quantity,connection_version,request_hash,state,actor_id,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,'queued',?,?,?)",[$company,$request,$old['product_key'],$old['listing_key'],$old['sku'],$old['remote_id'],$quantity,$source,(int)$connection['version'],$hash,(int)$actor['id'],$now,$now]);
        return $this->get($company,$request,$actor);
    }
    private function result(array $row):array
    {
        $origin=MarketplaceStockJob::origin($row['request_id'])??'hepsiburada';
        return ['requestId'=>$row['request_id'],'state'=>$row['state'],'quantity'=>(int)$row['quantity'],'provider'=>MarketplaceStockJob::CHANNELS[$origin],'sku'=>$row['sku'],'error'=>$row['error'],'updatedAt'=>$row['updated'],'complete'=>in_array($row['state'],self::TERMINAL,true)];
    }
}

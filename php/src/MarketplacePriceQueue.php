<?php
declare(strict_types=1);
namespace Omnigo;

final class MarketplacePriceQueue
{
    public function __construct(private Database $db){}
    public function install():void
    {
        if(IntegrationImportSchema::tableAvailable($this->db,'marketplace_price_jobs','state'))return;
        $this->db->run("CREATE TABLE IF NOT EXISTS marketplace_price_jobs(company_id INT NOT NULL, request_id VARCHAR(40) NOT NULL, listing_key VARCHAR(64) NOT NULL, product_key VARCHAR(64) NOT NULL, provider VARCHAR(24) NOT NULL, sku VARCHAR(180) NOT NULL, remote_id VARCHAR(180) NOT NULL, price BIGINT NOT NULL, actor_id INT NOT NULL, connection_version INT NOT NULL, state VARCHAR(20) NOT NULL, due BIGINT NOT NULL, created BIGINT NOT NULL, attempts INT NOT NULL DEFAULT 0, receipt VARCHAR(100), message VARCHAR(500), PRIMARY KEY(company_id,request_id,listing_key))".($this->db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
    }
    public function results(int $company,string $id):array
    {
        return $this->db->all('SELECT provider,sku,state,message FROM marketplace_price_jobs WHERE company_id=? AND request_id=? ORDER BY provider,sku',[$company,$id]);
    }
    public function add(int $company,array $input,array $actor):array
    {
        $price=$input['priceCents']??null;$id=$input['requestId']??null;$key=$input['productKey']??null;
        if(!is_int($price)||$price<1||$price>999999999999||!is_string($id)||!preg_match('/^[a-zA-Z0-9-]{16,40}$/D',$id)||!is_string($key)||($input['confirmed']??false)!==true)throw new \InvalidArgumentException('Ürün, satış fiyatı ve gönderim onayını kontrol edin.');
        $this->install();
        return TransactionRetry::run($this->db,function()use($company,$id,$key,$price,$actor){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            Security::admin($actor);
            if(!MarketplaceStockJob::allowed($this->db,MarketplaceStockPilot::load(),$company))throw new \DomainException('Model bu mağazada kapalı.');
            $old=$this->db->one('SELECT price,product_key,actor_id FROM marketplace_price_jobs WHERE company_id=? AND request_id=?',[$company,$id]);
            if($old){
                if((int)$old['price']!==$price||$old['product_key']!==$key||(int)$old['actor_id']!==(int)$actor['id'])throw new \DomainException('İşlem kimliği farklı bir gönderimde kullanılmış.');
                return $this->results($company,$id);
            }
            $targets=(new ActiveMarketplaceListings($this->db))->writableProductTargets($company,$key);
            if(!$targets)throw new \DomainException('Güncel, aktif ve eşleşen ilan bulunamadı.');
            foreach($targets as $t){
                $provider=array_search($t['provider'],MarketplaceStockJob::CHANNELS,true);
                $conn=$this->db->one('SELECT version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
                if(!$conn)throw new \DomainException('Bağlantı değişti. Listeyi yenileyin.');
                if($this->db->one("SELECT request_id FROM marketplace_price_jobs WHERE company_id=? AND listing_key=? AND state IN ('queued','checking','uncertain')",[$company,$t['key']]))throw new \DomainException('Bu ürünün önceki fiyat gönderimi henüz doğrulanmadı.');
                $this->db->run("INSERT INTO marketplace_price_jobs(company_id,request_id,listing_key,product_key,provider,sku,remote_id,price,actor_id,connection_version,state,due,created) VALUES(?,?,?,?,?,?,?,?,?,?,'queued',0,?)",[$company,$id,$t['key'],$key,$provider,$t['sku'],$t['catalogSku']??$t['remoteId'],$price,$actor['id'],$conn['version'],time()]);
            }
            return $this->results($company,$id);
        });
    }
}

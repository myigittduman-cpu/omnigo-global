<?php
declare(strict_types=1);
namespace Omnigo;

/** Durable snapshot of the offers approved together from one stock card. */
final class MarketplaceListingTransfers
{
    public const TABLE='marketplace_listing_transfers';
    public function __construct(private Database $db,private ?IntegrationVault $vault=null){}

    public function latest(int $company,string $card,?string $id=null,?array $fallbackLines=null): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$card))throw new \InvalidArgumentException('Stok kartı geçersiz.');
        $row=null;
        if($this->installed()){
            $key=InventoryStockCard::productKeySql($this->db,'t','card_key');
            $card=InventoryStockCard::primaryKey($this->db,$company,$card);
            $row=$this->db->one("SELECT t.* FROM marketplace_listing_transfers t WHERE t.company_id=? AND $key=?"
                .($id===null?' ORDER BY t.created DESC LIMIT 1':' AND t.request_id=?'),$id===null?[$company,$card]:[$company,$card,$id]);
        }
        if($row)return $this->result($row);
        return ['requestId'=>null,'done'=>true,'lines'=>$fallbackLines??$this->plan($company,$card)['lines']];
    }

    public function editor(int $company,string $card): array
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$card))throw new \InvalidArgumentException('Stok kartı geçersiz.');
        (new InventoryVisibility($this->db))->requireVisible($company,$card);
        $product=(new InventoryStock($this->db))->product($company,$card);
        $plan=$this->plan($company,$card);
        $snapshots=(new InventorySourceStock($this->db))->forKeys($company,[$card],true)[$card]??[];
        $snapshotsByProvider=[];
        foreach($snapshots as $offer)$snapshotsByProvider[$offer['provider']][]=$offer;
        $lines=[];
        foreach($plan['lines'] as $line){
            $offers=$snapshotsByProvider[$line['provider']]??[];
            // Keep the approved offer family distinct from closed or obsolete records.
            $active=array_values(array_filter($offers,static fn(array $offer):bool=>$offer['saleActive']===true&&$offer['health']==='current'));
            $targets=$plan['targets'][$line['provider']]??[];
            $shown=$active?:$offers;
            $lines[]=[...$line,'quantity'=>$targets?self::common($targets,'quantity'):self::common($shown,'quantity'),
                'priceCents'=>$targets?self::common($targets,'priceCents'):self::common($shown,'offerPriceCents'),
                'closed'=>$offers&&count(array_filter($offers,static fn(array $offer):bool=>$offer['saleActive']===false))===count($offers)];
        }
        $purchase=(new InventoryPurchasePrices($this->db))->forKeys($company,[$card])[$card]??[];
        return ['product'=>['key'=>$card,'title'=>$product['title'],'sku'=>$product['sku'],...$purchase],
            'lines'=>$lines,'latest'=>$this->latest($company,$card,fallbackLines:$plan['lines'])];
    }

    private static function common(array $offers,string $field): ?int
    {
        if(!$offers)return null;
        $first=$offers[0][$field];
        foreach($offers as $offer)if($offer[$field]!==$first)return null;
        return $first;
    }

    private static function channelChanges(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||!$raw||count($raw)>count(MarketplaceStockJob::CHANNELS))
            throw new \InvalidArgumentException('Pazaryeri değişikliklerini kontrol edin.');
        $changes=[];
        foreach($raw as $entry){
            if($entry instanceof \stdClass)$entry=get_object_vars($entry);
            if(!is_array($entry))throw new \InvalidArgumentException('Pazaryeri değişikliği geçersiz.');
            $provider=$entry['provider']??null;
            $quantity=$entry['quantity']??null;$price=$entry['priceCents']??null;
            if(!is_string($provider)||!in_array($provider,MarketplaceStockJob::CHANNELS,true)||isset($changes[$provider])
                ||($quantity===null&&$price===null)||($quantity!==null&&(!is_int($quantity)||$quantity<0||$quantity>100000000))
                ||($price!==null&&(!is_int($price)||$price<1||$price>999999999999))){
                throw new \InvalidArgumentException('Her pazaryeri için geçerli stok ve satış fiyatı girin.');
            }
            $changes[$provider]=['quantity'=>$quantity,'priceCents'=>$price];
        }
        ksort($changes,SORT_STRING);
        return $changes;
    }

    public function submit(int $company,array $input,array $actor): array
    {
        $card=$input['cardKey']??'';$id=$input['requestId']??'';
        $quantity=$input['quantity']??null;$price=$input['priceCents']??null;
        $changes=array_key_exists('changes',$input)?self::channelChanges($input['changes']):null;
        if($changes!==null&&($quantity!==null||$price!==null))throw new \InvalidArgumentException('Gönderim değerlerini tek biçimde belirtin.');
        if(!is_string($card)||!preg_match('/^[a-f0-9]{64}$/D',$card)||!is_string($id)||!preg_match('/^[a-zA-Z0-9-]{16,40}$/D',$id)
            ||($changes===null&&$quantity===null&&$price===null)||($quantity!==null&&(!is_int($quantity)||$quantity<0||$quantity>100000000))
            ||($price!==null&&(!is_int($price)||$price<1||$price>999999999999))||($input['confirmed']??false)!==true){
            throw new \InvalidArgumentException('Stok kartını, güncellenecek değerleri ve gönderim onayını kontrol edin.');
        }
        if(!$this->installed())self::install($this->db);
        return TransactionRetry::run($this->db,function()use($company,$card,$id,$quantity,$price,$changes,$actor){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            Security::admin($actor);
            if(!MarketplaceStockJob::allowed($this->db,MarketplaceStockPilot::load(),$company))throw new \DomainException('Bu mağazada pazaryeri stok yönetimi kapalı.');
            $old=$this->db->one('SELECT * FROM marketplace_listing_transfers WHERE company_id=? AND request_id=?',[$company,$id]);
            if($old){
                $same=$old['card_key']===$card&&(int)$old['actor_id']===(int)$actor['id']
                    &&($old['quantity']===null?null:(int)$old['quantity'])===$quantity
                    &&($old['price']===null?null:(int)$old['price'])===$price
                    &&(json_decode($old['payload'],true,64,JSON_THROW_ON_ERROR)['changes']??null)===$changes;
                if(!$same)throw new \DomainException('İşlem kimliği farklı bir gönderimde kullanılmış.');
                return $this->result($old);
            }
            if($this->db->one('SELECT request_id FROM marketplace_listing_transfers WHERE company_id=? AND card_key=? AND done=0',[$company,$card])){
                throw new \DomainException('Bu stok kartının önceki aktarımı devam ediyor. Sonucu düzenleme penceresinden izleyebilirsiniz.');
            }
            $plan=$this->plan($company,$card);
            if($changes!==null){
                (new InventoryVisibility($this->db))->requireVisible($company,$card);
                (new InventoryStock($this->db))->product($company,$card);
                $plan['changes']=$changes;
                $plan['targets']=array_intersect_key($plan['targets'],$changes);
                foreach($plan['lines'] as &$line)if(!isset($changes[$line['provider']]))$line['state']='skipped';
                unset($line);
            }
            if(array_sum(array_map('count',$plan['targets']))>100)throw new \DomainException('Bu kartta tek seferde gönderilebilecek ilan sınırı aşıldı.');
            $done=(int)!$plan['targets'];
            $row=['request_id'=>$id,'done'=>$done,'payload'=>json_encode($plan,JSON_THROW_ON_ERROR)];
            $this->db->run('INSERT INTO marketplace_listing_transfers(company_id,request_id,card_key,actor_id,quantity,price,payload,done,due,created)
                VALUES(?,?,?,?,?,?,?,?,0,?)',[$company,$id,$card,$actor['id'],$quantity,$price,$row['payload'],$done,(int)(microtime(true)*1000000)]);
            return $this->result($row);
        });
    }

    public function installed(): bool
    {
        return IntegrationImportSchema::tableAvailable($this->db,self::TABLE,'done');
    }

    public static function install(Database $db): void
    {
        $suffix=$db->mysql()?', INDEX transfer_due(done,due), INDEX transfer_card(company_id,card_key,created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':')';
        $text=$db->mysql()?'LONGTEXT':'TEXT';
        $db->run('CREATE TABLE IF NOT EXISTS marketplace_listing_transfers(
            company_id INT NOT NULL,request_id VARCHAR(40) NOT NULL,card_key VARCHAR(64) NOT NULL,
            actor_id INT NOT NULL,quantity INT,price BIGINT,payload '.$text.' NOT NULL,done INT NOT NULL,
            due BIGINT NOT NULL,created BIGINT NOT NULL,PRIMARY KEY(company_id,request_id)'.$suffix);
    }

    public static function restoredRow(array $row): array
    {
        if((int)$row['done']===1)return $row;
        $plan=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        foreach($plan['lines'] as &$line){
            if(!in_array($line['state'],['waiting','sending'],true))continue;
            $line['state']='uncertain';
            $line['message']='Yedekten gelen bekleyen aktarım durduruldu. Pazaryerinden son durumu kontrol edin.';
        }
        unset($line);
        return [...$row,'payload'=>json_encode($plan,JSON_THROW_ON_ERROR),'done'=>1,'due'=>0];
    }

    private function plan(int $company,string $card): array
    {
        $listings=new ActiveMarketplaceListings($this->db);
        $providers=$listings->cardProviders($company,$card);
        $targets=[];
        foreach($listings->writableCardTargets($company,$card) as $target){
            $channel=array_search($target['provider'],MarketplaceStockJob::CHANNELS,true);
            $connection=$this->db->one('SELECT version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$channel]);
            if($connection)$targets[$target['provider']][]=[...$target,'connectionVersion'=>(int)$connection['version']];
        }
        $unavailable=array_column((new MarketplaceListingCoverage($this->db,$this->vault))->unavailable($company,$card,[]),'state','provider');
        $targets=array_intersect_key($targets,$unavailable);
        $lines=[];
        foreach(CustomerMarketplaces::PROVIDERS as $label){
            $state=$unavailable[$label]??'unconnected';
            if(in_array($label,$providers,true))$state='ineligible';
            if(isset($targets[$label]))$state='waiting';
            $lines[]=['provider'=>$label,'state'=>$state,'details'=>[]];
        }
        return compact('lines','targets');
    }

    private function result(array $row): array
    {
        return ['requestId'=>$row['request_id'],'done'=>(bool)$row['done'],
            'lines'=>json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR)['lines']];
    }
}

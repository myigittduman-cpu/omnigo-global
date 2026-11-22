<?php
declare(strict_types=1);
namespace Omnigo;

/** Captures non-personal source line cost under the importer tenant lock. */
final class ProfitabilityOrderCapture
{
    public function __construct(private Database $db) {}

    public function observe(int $company,array $delivery,?string $sourceOrderTime=null): void
    {
        if(empty($delivery['details']['products'])||!empty($delivery['details']['superseded'])||!$this->db->pdo->inTransaction())return;
        $channel=$delivery['provider'];
        $provider=array_search($channel,OrderList::PROVIDERS,true);
        if($provider===false)return;
        $external=(string)($channel==='Pazarama'?$delivery['externalId']:$delivery['orderNumber']);
        $number=(string)$delivery['orderNumber'];
        if($external===''||$number===''||mb_strlen($external)>180||mb_strlen($number)>180)return;
        // Only the marketplace's Created history supplies n11 order time.
        [$ordered,$earliest,$latest]=self::interval($provider,$sourceOrderTime??$delivery['createdAt']??'');
        if($ordered===null||$ordered>time())return;

        $this->db->run('SAVEPOINT profitability_order_capture');
        try{
            $start=ProfitabilitySchema::startedAt($this->db);
            if($start!==null&&$ordered>=$start)$this->capture($company,$provider,$external,$number,$ordered,$earliest,$latest,$delivery);
        }catch(\Throwable $error){
            // Profitability is auxiliary. A failed snapshot must not reject a valid live order.
            if(!$this->db->pdo->inTransaction())throw $error;
            $this->db->run('ROLLBACK TO SAVEPOINT profitability_order_capture');
            error_log('Omnigo kârlılık sipariş kaydı: '.get_class($error));
        }
        $this->db->run('RELEASE SAVEPOINT profitability_order_capture');
    }

    private function capture(int $company,string $provider,string $external,string $number,int $ordered,int $earliest,int $latest,array $delivery): void
    {
        $channel=$delivery['provider'];
        $scope=[$company,$provider,$external];
        $order=$this->db->one('SELECT ordered_at FROM profitability_orders WHERE company_id=? AND provider=? AND external_id=?',$scope);
        if($order&&(int)$order['ordered_at']!==$ordered)return;
        if(!$order)$this->db->run('INSERT INTO profitability_orders(company_id,provider,external_id,order_number,ordered_at) VALUES(?,?,?,?,?)',
            [...$scope,$number,$ordered]);
        $frozen=array_fill_keys(array_column($this->db->all('SELECT line_key FROM profitability_lines WHERE company_id=? AND provider=? AND external_id=?',$scope),'line_key'),true);
        $identity=new ProfitabilityProductIdentity($this->db);
        $prices=new ProfitabilityPrices($this->db);
        $history=new ProfitabilityCostHistory($this->db);
        $useRules=$history->available();
        $binding=new ProfitabilityCostBinding($this->db);
        if($useRules&&$frozen)$binding->fromDelivery($company,$delivery);
        $priceCache=[];
        foreach($delivery['details']['products'] as $line){
            $key=self::lineKey($line);
            $quantity=$line['quantity']??null;
            if($key===null||isset($frozen[$key])||!is_int($quantity)||$quantity<1||$quantity>100000000)continue;
            $sku=ShipmentProductCode::sourceCode($channel,$line);
            $product=$identity->key($company,$channel,$line,$sku);
            $ruleProduct=$product??($useRules?$binding->inventoryEvidence($company,$channel,$external,$line,$sku,$ordered):null);
            if($product!==null&&!array_key_exists($product,$priceCache))$priceCache[$product]=$prices->at($company,$product,$earliest,$latest);
            $applied=$useRules&&$ruleProduct!==null?$history->amountAt($company,$ruleProduct,$ordered):null;
            $title=mb_substr((string)($line['title']??''),0,1000);
            $this->db->run('INSERT INTO profitability_lines(company_id,provider,external_id,line_key,sku,title,quantity,unit_purchase_cents,match_key) VALUES(?,?,?,?,?,?,?,?,?)',
                [...$scope,$key,mb_substr($sku,0,180),$title,$quantity,$applied??$priceCache[$product]??null,self::matchKey($line)]);
            if($useRules&&$ruleProduct!==null)$history->bind($company,$provider,$external,$key,$ruleProduct);
            $frozen[$key]=true;
        }
    }

    public static function lineKey(array $line): ?string
    {
        $id=ShipmentProductCode::normalize($line['lineId']??null);
        return $id===''?null:hash('sha256',$id);
    }

    public static function matchKey(array $line): string
    {
        // Read-side fallback is confined to the already verified order line for sources
        // whose existing financial summary does not carry its line ID.
        return hash('sha256',json_encode([(string)($line['sku']??''),(string)($line['title']??''),$line['quantity']??null],JSON_THROW_ON_ERROR));
    }

    private static function interval(string $provider,string $value): array
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.(\d{1,6}))?)?(?:Z|[+-]\d{2}:\d{2})?$/D',$value,$parts))return [null,0,0];
        try{$date=new \DateTimeImmutable($value,new \DateTimeZone('Europe/Istanbul'));}
        catch(\Exception){return [null,0,0];}
        if(\DateTimeImmutable::getLastErrors()!==false)return [null,0,0];
        $start=$date->getTimestamp();
        $fraction=$parts[1]??'';
        $earliest=(int)$date->format('Uu');
        $precision=$fraction!==''?10**(6-strlen($fraction)):($provider==='pazarama'||strlen($value)===16?60000000:1000000);
        return [$start,$earliest,min($earliest+$precision-1,ProfitabilityPrices::clock())];
    }
}

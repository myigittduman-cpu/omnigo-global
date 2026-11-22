<?php
declare(strict_types=1);
namespace Omnigo;

/** Recover an old snapshot's inventory identity from its exact seller order and line evidence. */
final class ProfitabilityCostBinding
{
    public function __construct(private Database $db) {}

    /** Caller holds the tenant lock used by both the order importer and the migration. */
    public function fromDelivery(int $company,array $delivery): int
    {
        $channel=$delivery['provider']??'';
        $provider=array_search($channel,OrderList::PROVIDERS,true);
        $number=(string)($delivery['orderNumber']??'');
        $external=$channel==='Pazarama'?(string)($delivery['externalId']??''):$number;
        if($provider===false||$number===''||$external===''||empty($delivery['details']['products'])||!empty($delivery['details']['superseded']))return 0;
        $rows=$this->db->all('SELECT l.line_key,l.match_key,l.sku,l.quantity,o.ordered_at FROM profitability_lines l
            JOIN profitability_orders o ON o.company_id=l.company_id AND o.provider=l.provider AND o.external_id=l.external_id
            LEFT JOIN profitability_line_products p ON p.company_id=l.company_id AND p.provider=l.provider AND p.external_id=l.external_id AND p.line_key=l.line_key
            WHERE l.company_id=? AND l.provider=? AND l.external_id=? AND o.order_number=? AND p.product_key IS NULL',
            [$company,$provider,$external,$number]);
        if(!$rows)return 0;
        $frozen=array_column($rows,null,'line_key');
        $identity=new ProfitabilityProductIdentity($this->db);
        $matches=[];
        foreach($delivery['details']['products'] as $line){
            $key=ProfitabilityOrderCapture::lineKey($line);
            $row=$frozen[$key]??null;
            if(!$row)continue;
            $sku=ShipmentProductCode::sourceCode($channel,$line);
            $product=null;
            if($sku===$row['sku']&&ProfitabilityOrderCapture::matchKey($line)===$row['match_key']){
                $product=$identity->key($company,$channel,$line,$sku);
                $product??=$this->inventoryEvidence($company,$channel,$external,$line,$sku,(int)$row['ordered_at']);
            }
            if(array_key_exists($key,$matches)&&$matches[$key]!==$product)$product=null;
            $matches[$key]=$product;
        }
        $history=new ProfitabilityCostHistory($this->db);
        $count=0;
        foreach($matches as $key=>$product){
            if($product===null)continue;
            $history->bind($company,$provider,$external,$key,$product);
            $count++;
        }
        return $count;
    }

    /** Use independent stock evidence only for sources without a conflicting marketplace product reference. */
    public function inventoryEvidence(int $company,string $channel,string $external,array $line,string $sku,int $orderedAt): ?string
    {
        if($sku===''||ShipmentSourceProduct::stored($channel,$line))return null;
        $stockLine=hash('sha256',$channel.':'.$external.':'.$sku);
        $balance=$this->db->one('SELECT product_key,placed_at FROM inventory_balances
            WHERE company_id=? AND provider=? AND line_key=? AND order_number=? AND ordered=?',
            [$company,$channel,$stockLine,$external,$line['quantity']]);
        if($balance&&strtotime($balance['placed_at'])===$orderedAt)return $balance['product_key'];
        return null;
    }

    /** One bounded primary-key range, outside live writes except for short per-store transactions. */
    public function batch(DeliveryCipher $cipher,int $afterId,int $lastId): array
    {
        $active=ProfitabilitySchema::startedAt($this->db);
        if($active===null)return ['cursor'=>$lastId,'bound'=>0];
        $scanned=$this->db->all('SELECT id,company_id,provider,external_id,updated FROM delivery_orders WHERE id>? AND id<=? ORDER BY id LIMIT 100',[$afterId,$lastId]);
        $bound=0;
        foreach($scanned as $row){
            $afterId=(int)$row['id'];
            if(strtotime($row['updated'])<$active)continue;
            $company=(int)$row['company_id'];
            $bound+=TransactionRetry::run($this->db,function()use($company,$row,$cipher){
                $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                $saved=$this->db->one('SELECT payload FROM delivery_orders WHERE id=? AND company_id=? AND provider=? AND external_id=?',
                    [$row['id'],$company,$row['provider'],$row['external_id']]);
                if(!$saved)return 0;
                $delivery=$cipher->open($company,'order:'.$row['provider'].':'.$row['external_id'],$saved['payload']);
                if(($delivery['externalId']??null)!==$row['external_id']||($delivery['provider']??null)!==$row['provider'])return 0;
                return $this->fromDelivery($company,$delivery);
            });
        }
        return ['cursor'=>count($scanned)<100?$lastId:$afterId,'bound'=>$bound];
    }
}

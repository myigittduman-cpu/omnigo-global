<?php
declare(strict_types=1);
namespace Omnigo;

/** Prices belong to tenant/product identities, never to row positions or stock visibility. */
final class InventoryPurchasePrices
{
    public function __construct(private Database $db){}
    public function forKeys(int $company,array $keys):array
    {
        if(!$keys)return [];$in=implode(',',array_fill(0,count($keys),'?'));$result=[];
        foreach($this->db->all('SELECT product_key,amount_cents,version,updated FROM inventory_purchase_prices WHERE company_id=? AND product_key IN ('.$in.')',[$company,...$keys]) as $row)$result[$row['product_key']]=['purchasePriceCents'=>(int)$row['amount_cents'],'purchasePriceVersion'=>(int)$row['version'],'purchasePriceUpdated'=>$row['updated']];
        foreach(InventoryPurchaseTax::forKeys($this->db,$company,$keys) as $key=>$tax)if(isset($result[$key]))$result[$key]+=$tax;
        return $result;
    }
    public function save(int $company,array $input,array $actor):array
    {
        $key=$input['productKey']??null;$request=$input['requestId']??null;$amount=$input['amountCents']??null;$version=$input['version']??null;
        if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!is_string($request)||!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$request)||!is_int($amount)||$amount<0||$amount>100000000000||!is_int($version)||$version<0)throw new \InvalidArgumentException('Ürünü ve alış fiyatını kontrol edin. En fazla iki ondalık basamak kullanın.');
        if(!in_array($actor['role']??'', ['admin','client'],true)||(($actor['role']??'')!=='admin'&&(int)($actor['company_id']??0)!==$company))throw new \DomainException('Bu müşterinin alış fiyatını değiştiremezsiniz.');
        $explicit=array_key_exists('applyToPastOrders',$input);
        if($explicit&&!is_bool($input['applyToPastOrders']))throw new \InvalidArgumentException('Geçmiş siparişlere uygulama seçimini kontrol edin.');
        $retroactive=$input['applyToPastOrders']??false;
        $tax=InventoryPurchaseTax::normalize($input);
        if($tax!==null&&!InventoryPurchaseTax::available($this->db))throw new \DomainException('KDV seçimli alış fiyatı kaydı henüz hazır değil.');
        $hashValues=[$key,$amount,$version,(int)$actor['id']];if($tax!==null)$hashValues[]=$tax;
        // Omitting the option and explicitly selecting future orders have the same request identity.
        if($retroactive)$hashValues[]=['applyToPastOrders'=>true];
        $hash=hash('sha256',json_encode($hashValues,JSON_THROW_ON_ERROR));
        $amount=$tax['purchaseGrossCents']??$amount;
        return TransactionRetry::run($this->db,function()use($company,$key,$request,$amount,$version,$actor,$hash,$tax,$explicit,$retroactive){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $previous=$this->db->one('SELECT request_hash,result FROM inventory_price_requests WHERE company_id=? AND request_id=?',[$company,$request]);
            if($previous){if(!hash_equals($previous['request_hash'],$hash))throw new \DomainException('İşlem anahtarı farklı bir kayıt için kullanılmış.');return [...json_decode($previous['result'],true,16,JSON_THROW_ON_ERROR),'replayed'=>true];}
            $product=(new InventoryStock($this->db))->product($company,$key);
            (new InventoryVisibility($this->db))->requireVisible($company,$key);
            $old=$this->db->one('SELECT * FROM inventory_purchase_prices WHERE company_id=? AND product_key=?'.$this->db->lock(),[$company,$key]);
            if((int)($old['version']??0)!==$version)throw new \DomainException('Alış fiyatı başka bir kullanıcı tarafından değiştirildi. Güncel fiyatı kontrol edin.');
            $currentTax=InventoryPurchaseTax::forKeys($this->db,$company,[$key])[$key]??null;
            $costHistory=new ProfitabilityCostHistory($this->db);
            $historyReady=$costHistory->available();
            if($explicit&&!$historyReady)throw new \DomainException('Sipariş maliyeti altyapısı henüz hazır değil. Lütfen tekrar deneyin.');
            $priceChanged=$old===null||(int)$old['amount_cents']!==$amount||($tax!==null&&$tax!==$currentTax);
            $changed=$priceChanged||$retroactive;
            $next=$version+(int)$changed;
            $instant=ProfitabilityPrices::clock();
            $second=intdiv($instant,1000000);
            $now=gmdate('c',$second);
            if($changed){
                (new ProfitabilityPrices($this->db))->changed($company,$key,$old,$next,$amount,$instant);
                if($historyReady)$costHistory->record($company,$key,$next,$retroactive?0:$second,$amount);
                if($old)$this->db->run('UPDATE inventory_purchase_prices SET amount_cents=?,version=?,actor_id=?,updated=? WHERE company_id=? AND product_key=?',[$amount,$next,$actor['id'],$now,$company,$key]);
                else $this->db->run('INSERT INTO inventory_purchase_prices(company_id,product_key,amount_cents,version,actor_id,updated) VALUES(?,?,?,?,?,?)',[$company,$key,$amount,$next,$actor['id'],$now]);
                if($priceChanged){
                    (new InventorySettings($this->db))->ensureJob($company);
                    (new InventoryChangeEvents($this->db))->record($company,hash('sha256','purchase-price:'.$request),$key,(int)$actor['id'],['events'=>['purchase_price_updated'],'productTitle'=>$product['title'],'sku'=>$product['sku'],'previousPriceCents'=>$old===null?null:(int)$old['amount_cents'],'purchasePriceCents'=>$amount]);
                }
            }
            if($retroactive&&!$priceChanged&&$tax===null)$tax=$currentTax;
            if($tax!==null)InventoryPurchaseTax::save($this->db,$company,$key,$next,$tax);
            $result=['productKey'=>$key,'purchasePriceCents'=>$amount,'purchasePriceVersion'=>$next,'changed'=>$changed,'replayed'=>false,'applyToPastOrders'=>$retroactive,...($tax??[])];
            $this->db->run('INSERT INTO inventory_price_requests(company_id,request_id,request_hash,result) VALUES(?,?,?,?)',[$company,$request,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

/** Manual receipts/sales adjust one shared pool without rewriting the marketplace snapshot. */
final class InventoryHorozStock
{
    public function __construct(private Database $db){}
    public function change(int $company,string $product,string $request,int $version,string $mode,int $amount,int $actor,string $note,?string $lease,?string $sourceVersion):array
    {
        $event=hash('sha256','manual:'.$request);$hash=hash('sha256',json_encode([$product,$version,$mode,$amount,$actor,$note,$sourceVersion,'horoz'],JSON_THROW_ON_ERROR));
        return TransactionRetry::run($this->db,function()use($company,$product,$event,$hash,$version,$mode,$amount,$actor,$note,$lease,$sourceVersion){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if((new InventorySettings($this->db))->preferences($company)['mode']!=='horoz')throw new \DomainException('Stok modeli değişti. Sayfayı yenileyip yeniden deneyin.');
            $job=$this->db->one('SELECT * FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
            $previous=$this->db->one('SELECT * FROM inventory_ledger WHERE company_id=? AND event_key=?',[$company,$event]);
            if($previous){if(!hash_equals($previous['request_hash'],$hash))throw new \DomainException('İşlem anahtarı farklı bir kayıt için kullanılmış.');return ['quantity'=>(int)$previous['quantity_after'],'replayed'=>true];}
            if($job&&(int)$job['enabled']===1&&($lease===null||!hash_equals((string)$job['lease_token'],$lease)||(int)$job['lease_until']<=time()))throw new \DomainException('Stok kayıt görevinin süresi doldu.');
            $row=(new InventoryStock($this->db))->product($company,$product);
            (new InventoryVisibility($this->db))->requireVisible($company,$product);
            if((int)$row['version']!==$version)throw new \DomainException('Stok başka bir işlemle güncellendi. Güncel miktarı kontrol edin.');
            $snapshots=(new InventorySourceStock($this->db))->forKeys($company,[$product])[$product]??[];
            $manual=$this->db->one('SELECT * FROM inventory_shared_stock WHERE company_id=? AND product_key=?'.$this->db->lock(),[$company,$product]);
            $stock=InventorySharedStock::calculate($snapshots,$manual??[]);$old=$stock['quantity'];
            if($old===null&&($snapshots||!in_array($mode,['initialize','set'],true)))throw new \DomainException('Güncel ortak stok doğrulanamadı. Canlı aktarım tamamlandıktan sonra yeniden deneyin.');
            if($old!==null&&($sourceVersion===null||!hash_equals((string)$stock['sourceVersion'],$sourceVersion)))throw new \DomainException('Canlı stok miktarı değişti. Güncel miktarı kontrol ederek yeniden kaydedin.');
            if($mode==='initialize'&&$old!==null)throw new \DomainException('Ürünün stok bilgisi zaten mevcut.');
            if($mode==='store_return'){
                $card=InventoryStockCard::productKeySql($this->db,'l');
                $sales=(int)$this->db->one("SELECT COALESCE(SUM(-l.delta),0) sold FROM inventory_ledger l WHERE l.company_id=? AND $card=? AND l.kind IN ('horoz_sale','horoz_return')",[$company,$product])['sold'];
                if($amount>$sales)throw new \DomainException('İade adedi kayıtlı net mağaza satışını aşamaz.');
            }
            $next=in_array($mode,['initialize','set'],true)?$amount:$old+(in_array($mode,['add','store_return'],true)?$amount:-$amount);
            if(abs($next)>1000000000)throw new \DomainException('Stok miktarı izin verilen aralığın dışında.');
            $delta=$next-($old??0);$adjustment=(int)($manual['adjustment']??0)+$delta;
            $manualQuantity=$manual['manual_quantity']??null;
            if($old===null){$manualQuantity=$amount;$adjustment=0;}
            if($manual)$this->db->run('UPDATE inventory_shared_stock SET adjustment=?,manual_quantity=? WHERE company_id=? AND product_key=?',[$adjustment,$manualQuantity,$company,$product]);
            else $this->db->run('INSERT INTO inventory_shared_stock(company_id,product_key,adjustment,manual_quantity) VALUES(?,?,?,?)',[$company,$product,$adjustment,$manualQuantity]);
            $kind=match($mode){'store_sale'=>'horoz_sale','store_return'=>'horoz_return',default=>'horoz_'.$mode};
            $now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
            $this->db->run('INSERT INTO inventory_ledger(company_id,event_key,product_key,kind,delta,quantity_after,request_hash,actor_id,note,created) VALUES(?,?,?,?,?,?,?,?,?,?)',[$company,$event,$product,$kind,$delta,$next,$hash,$actor,$note,$now]);
            $this->db->run('UPDATE inventory_products SET version=version+1,updated=? WHERE company_id=? AND product_key=?',[$now,$company,$product]);
            (new InventoryChangeEvents($this->db))->record($company,$event,$product,$actor,['events'=>['inventory_adjusted'],'stockMode'=>'horoz','productTitle'=>$row['title'],'sku'=>$row['sku'],'quantityBefore'=>$old,'quantity'=>$next,'delta'=>$delta,'movementKind'=>$kind,'marketplaceQuantity'=>$stock['marketplaceQuantity'],'stockDifference'=>$stock['marketplaceQuantity']===null?null:$next-$stock['marketplaceQuantity']]);
            return ['quantity'=>$next,'version'=>$version+1,'replayed'=>false];
        });
    }
}

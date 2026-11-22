<?php
declare(strict_types=1);
namespace Omnigo;

/** All stock writers serialize on the tenant/product row. No marketplace writes. */
final class InventoryStock
{
    public function __construct(private Database $db){}

    public function change(int $company,string $product,string $request,int $version,string $mode,int $amount,int $actor,string $note,?string $lease=null,?string $sourceVersion=null): array
    {
        if(!in_array($mode,['initialize','set','add','subtract','store_sale','store_return'],true)||abs($amount)>100000000||($amount<0&&in_array($mode,['add','subtract'],true))||(in_array($mode,['store_sale','store_return'],true)&&$amount<1)||strlen(trim($note))<3||strlen($note)>500||!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$request))throw new \InvalidArgumentException('Stok işlemini ve açıklamayı kontrol edin.');
        if((new InventorySettings($this->db))->preferences($company)['mode']==='horoz')return (new InventoryHorozStock($this->db))->change($company,$product,$request,$version,$mode,$amount,$actor,$note,$lease,$sourceVersion);
        $hash=hash('sha256',json_encode([$product,$version,$mode,$amount,$actor,$note],JSON_THROW_ON_ERROR));
        $event=hash('sha256','manual:'.$request);
        return $this->db->transaction(function()use($company,$product,$event,$hash,$version,$mode,$amount,$actor,$note,$lease){
            (new InventorySettings($this->db))->requireStore($company);
            $job=$this->db->one('SELECT * FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
            $previous=$this->db->one('SELECT * FROM inventory_ledger WHERE company_id=? AND event_key=?',[$company,$event]);
            if($previous){
                if(!hash_equals($previous['request_hash'],$hash))throw new \DomainException('Bu işlem anahtarı farklı bir kayıt için kullanılmış.');
                return ['quantity'=>(int)$previous['quantity_after'],'replayed'=>true];
            }
            if($job&&(int)$job['enabled']===1){
                if($lease===null||!hash_equals((string)$job['lease_token'],$lease)||(int)$job['lease_until']<=time())throw new \DomainException('Stok kayıt görevinin süresi doldu.');
            }
            $row=$this->product($company,$product);
            if($row['quantity']===null&&$this->db->one("SELECT product_key FROM inventory_links WHERE company_id=? AND source_product=? AND state='active'",[$company,$product]))throw new \DomainException('Bu katalog ürünü manuel stok ürününe bağlı. Stoğu bağlı ürün üzerinden düzenleyin.');
            if((int)$row['version']!==$version)throw new \DomainException('Stok başka bir işlemle güncellendi. Güncel miktarı kontrol edip yeniden kaydedin.');
            if($row['quantity']===null&&!in_array($mode,['set','initialize'],true))throw new \DomainException('Önce sayım sonucunu başlangıç stoku olarak kaydedin.');
            if($row['quantity']!==null&&$mode==='initialize')throw new \DomainException('Ürün zaten stok listesine eklenmiş.');
            $old=$row['quantity']===null?null:(int)$row['quantity'];
            if($mode==='store_return'){
                $sold=(int)$this->db->one("SELECT COALESCE(SUM(-delta),0) sold FROM inventory_ledger WHERE company_id=? AND product_key=? AND kind IN ('store_sale','store_return')",[$company,$product])['sold'];
                if($amount>$sold)throw new \DomainException('İade adedi kayıtlı net mağaza satışını aşamaz.');
            }
            $next=in_array($mode,['initialize','set'],true)?$amount:($old+(in_array($mode,['add','store_return'],true)?$amount:-$amount));
            $this->write($company,$row,$event,$mode,$next-($old??0),$next,$hash,$actor,$note);
            // Absolute counts include all source events checked before the count.
            // Relative receipts/corrections must not move this historical cut-off.
            if($mode==='set')$this->db->run('UPDATE inventory_products SET baseline_at=updated WHERE company_id=? AND product_key=?',[$company,$product]);
            return ['quantity'=>$next,'version'=>$version+1,'replayed'=>false,'initialQuantity'=>$mode==='initialize'?$amount:null,'reservedQuantity'=>0];
        });
    }

    /** Called inside the projection transaction after locking the job and product. */
    public function product(int $company,string $key): array
    {
        return $this->db->one('SELECT * FROM inventory_products WHERE company_id=? AND product_key=?'.$this->db->lock(),[$company,$key])??throw new \OutOfBoundsException('Ürün bu müşteride bulunamadı.');
    }
    public function write(int $company,array $product,string $event,string $kind,int $delta,int $next,string $hash,?int $actor,string $note): void
    {
        if(abs($next)>1000000000)throw new \DomainException('Stok miktarı izin verilen aralığın dışında.');
        $now=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $this->db->run('INSERT INTO inventory_ledger(company_id,event_key,product_key,kind,delta,quantity_after,request_hash,actor_id,note,created) VALUES(?,?,?,?,?,?,?,?,?,?)',[$company,$event,$product['product_key'],$kind,$delta,$next,$hash,$actor,$note,$now]);
        $this->db->run('UPDATE inventory_products SET quantity=?,baseline_at=COALESCE(baseline_at,?),version=version+1,updated=? WHERE company_id=? AND product_key=?',[$next,$now,$now,$company,$product['product_key']]);
    }
}

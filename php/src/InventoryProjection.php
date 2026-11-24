<?php
declare(strict_types=1);
namespace Omnigo;

/** Independent projection of verified order line snapshots; never edits source orders. */
final class InventoryProjection
{
    public function __construct(private Database $db){}

    public function apply(int $company,array $line,int $sequence,?string $lease=null): bool
    {
        if($sequence<1||!in_array($line['provider']??null,InventoryCatalog::PROVIDERS,true))throw new \InvalidArgumentException('Stok taraması geçersiz.');
        foreach(['ordered','cancelled','returned'] as $field)if(!is_int($line[$field]??null)||$line[$field]<0||$line[$field]>1000000)throw new \InvalidArgumentException('Sipariş adedi doğrulanamadı.');
        if($line['cancelled']+$line['returned']>$line['ordered'])throw new \InvalidArgumentException('İptal ve iade adetleri sipariş adedini aşıyor.');
        foreach(['provider','lineKey','productKey','orderNumber','placedAt'] as $field)if(!is_string($line[$field]??null)||$line[$field]==='')throw new \InvalidArgumentException('Sipariş kimliği eksik.');
        if(!preg_match('/^[a-f0-9]{64}$/D',$line['lineKey'])||!preg_match('/^[a-f0-9]{64}$/D',$line['productKey'])||strlen($line['orderNumber'])>180)throw new \InvalidArgumentException('Sipariş kimliği geçersiz.');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D',$line['placedAt']))throw new \InvalidArgumentException('Sipariş zamanı doğrulanamadı.');
        $placed=new \DateTimeImmutable($line['placedAt']);
        if(\DateTimeImmutable::getLastErrors()!==false)throw new \InvalidArgumentException('Sipariş zamanı geçersiz.');
        if(($line['invoiceCents']??null)!==null&&(!is_int($line['invoiceCents'])||$line['invoiceCents']<0))throw new \InvalidArgumentException('Fatura tutarı doğrulanamadı.');
        $hash=hash('sha256',json_encode($line,JSON_THROW_ON_ERROR));
        return $this->db->transaction(function()use($company,$line,$sequence,$lease,$hash,$placed){
            $job=$this->db->one('SELECT * FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
            if($lease!==null||($job&&(int)$job['enabled']===1)){
                if(!$job||!(int)$job['enabled']||$lease===null||!hash_equals((string)$job['lease_token'],$lease)||(int)$job['lease_until']<=time()||(int)$job['sequence_no']!==$sequence)throw new \DomainException('Stok görevinin süresi doldu.');
            }
            $sourceKey=$line['productKey'];
            $link=$this->db->one("SELECT l.product_key,s.sku source_sku,t.sku target_sku FROM inventory_links l
                JOIN inventory_products s ON s.company_id=l.company_id AND s.product_key=l.source_product
                JOIN inventory_products t ON t.company_id=l.company_id AND t.product_key=l.product_key
                WHERE l.company_id=? AND l.provider=? AND l.source_product=? AND l.state='active'",[$company,$line['provider'],$sourceKey]);
            if($link&&InventoryLiveSkuIdentity::normalize($link['source_sku'])!==InventoryLiveSkuIdentity::normalize($link['target_sku']))$link=null;
            if($link)$line['productKey']=$link['product_key'];
            $stock=new InventoryStock($this->db);$product=$stock->product($company,$line['productKey']);
            $old=$this->db->one('SELECT * FROM inventory_balances WHERE company_id=? AND provider=? AND line_key=?'.$this->db->lock(),[$company,$line['provider'],$line['lineKey']]);
            $remapped=$old&&$old['product_key']!==$line['productKey'];
            if($remapped&&(!$link||$old['product_key']!==$sourceKey))throw new \DomainException('İşlenmiş sipariş satırının ürün eşleştirmesi değişmiş; mutabakat gerekiyor.');
            if($old&&(int)$old['sequence_no']>$sequence)return false;
            if($old&&(int)$old['sequence_no']===$sequence&&$old['source_hash']!==$hash)throw new \DomainException('Aynı taramada çelişkili sipariş satırı bulundu.');
            $net=$line['ordered']-$line['cancelled']-$line['returned'];
            // Orders are sales statistics only. Current online stock comes from the catalogue;
            // store stock is a separate manual pool. Never deduct either snapshot a second time.
            (new InventoryPending($this->db))->observe($company,$product,$line,$old);
            $consumed=$net;
            if($old&&!$remapped&&$old['source_hash']===$hash){
                $this->db->run('UPDATE inventory_balances SET sequence_no=? WHERE company_id=? AND provider=? AND line_key=?',[$sequence,$company,$line['provider'],$line['lineKey']]);return false;
            }
            $now=gmdate('Y-m-d\TH:i:s\Z');
            $params=[$line['productKey'],$line['orderNumber'],$sequence,$hash,$line['ordered'],$line['cancelled'],$line['returned'],$consumed,$line['invoiceCents']??null,$line['placedAt'],$now];
            if($old)$this->db->run('UPDATE inventory_balances SET product_key=?,order_number=?,sequence_no=?,source_hash=?,ordered=?,cancelled=?,returned=?,consumed=?,invoice_cents=?,placed_at=?,updated=? WHERE company_id=? AND provider=? AND line_key=?',[...$params,$company,$line['provider'],$line['lineKey']]);
            else $this->db->run('INSERT INTO inventory_balances(product_key,order_number,sequence_no,source_hash,ordered,cancelled,returned,consumed,invoice_cents,placed_at,updated,company_id,provider,line_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$params,$company,$line['provider'],$line['lineKey']]);
            return true;
        });
    }
}

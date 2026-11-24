<?php
declare(strict_types=1);
namespace Omnigo;

/** Matching is queued against fresh, read-only catalogue scans, never marketplace writes. */
final class InventoryMatching
{
    public function __construct(private Database $db){}
    public static function validateSelection(mixed $selection,bool $required=true):array
    {
        if(!is_array($selection)||count($selection)>count(InventoryCatalog::PROVIDERS)||($required&&!$selection))throw new \InvalidArgumentException('En az bir pazaryeri seçin.');
        $result=[];
        foreach($selection as $entry){
            $provider=$entry['provider']??null;$code=$entry['code']??'';
            if(!in_array($provider,InventoryCatalog::PROVIDERS,true)||!is_string($code)||strlen(trim($code))>180||isset($result[$provider]))throw new \InvalidArgumentException('Pazaryeri ve stok kodunu kontrol edin.');
            $result[$provider]=['provider'=>$provider,'code'=>trim($code)];
        }
        return array_values($result);
    }
    public function request(int $company,string $key,array $selection):array
    {
        $selection=self::validateSelection($selection);
        return $this->db->transaction(function()use($company,$key,$selection){
            $job=$this->db->one('SELECT enabled FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
            if(!$job||!(int)$job['enabled'])throw new \DomainException('Bu müşterinin stok bölümü henüz etkin değil.');
            $product=(new InventoryStock($this->db))->product($company,$key);
            (new InventoryVisibility($this->db))->requireVisible($company,$key);
            if(!$this->db->one('SELECT product_key FROM inventory_manual_products WHERE company_id=? AND product_key=?',[$company,$key]))throw new \DomainException('Bu işlem manuel eklenen ürünler içindir.');
            if($product['quantity']===null)throw new \DomainException('Önce başlangıç stoğunu kaydedin.');
            $this->enqueue($company,$key,$selection);
            return ['queued'=>true];
        });
    }
    /** Caller holds the tenant job lock. Active links are never restarted or silently removed. */
    public function enqueue(int $company,string $key,array $selection):void
    {
        $now=gmdate('c');
        foreach($selection as $entry){
            $old=$this->db->one('SELECT state FROM inventory_links WHERE company_id=? AND product_key=? AND provider=?',[$company,$key,$entry['provider']]);
            if(($old['state']??'')==='active')continue;
            if($old)$this->db->run("UPDATE inventory_links SET state='pending',match_code=?,reason='',requested_at=? WHERE company_id=? AND product_key=? AND provider=?",[$entry['code'],$now,$company,$key,$entry['provider']]);
            else $this->db->run("INSERT INTO inventory_links(company_id,product_key,provider,match_code,state,reason,requested_at) VALUES(?,?,?,?,'pending','',?)",[$company,$key,$entry['provider'],$entry['code'],$now]);
        }
        $this->db->run('UPDATE inventory_jobs SET next_run=0 WHERE company_id=?',[$company]);
    }
    public function process(int $company,string $lease):void
    {
        foreach($this->db->all("SELECT product_key,provider FROM inventory_links WHERE company_id=? AND state='pending' LIMIT 30",[$company]) as $entry){
            $this->db->transaction(function()use($company,$lease,$entry){
                $job=$this->db->one('SELECT * FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
                if(!$job||!hash_equals((string)$job['lease_token'],$lease)||(int)$job['lease_until']<=time())throw new \DomainException('Eşleştirme görevi yeniden denenecek.');
                $link=$this->db->one('SELECT * FROM inventory_links WHERE company_id=? AND product_key=? AND provider=?'.$this->db->lock(),[$company,$entry['product_key'],$entry['provider']]);
                if(!$link||$link['state']!=='pending')return;
                $reason=$this->check($company,$link);
                if($reason!==null)$this->db->run("UPDATE inventory_links SET state='unmatched',reason=? WHERE company_id=? AND product_key=? AND provider=?",[$reason,$company,$link['product_key'],$link['provider']]);
            });
        }
    }
    /** Null means pending or active; nonempty string is an actionable mismatch. */
    private function check(int $company,array $link):?string
    {
        $slug=array_search($link['provider'],InventoryCatalogPage::LABELS,true);
        if($slug===false||!in_array($slug,InventoryCatalogPage::SUPPORTED,true))return 'Bu pazaryeri için ürün kataloğu bağlantısı henüz hazır değil. Manuel takip devam ediyor.';
        $job=$this->db->one('SELECT * FROM inventory_catalog_jobs WHERE company_id=? AND provider=?',[$company,$slug]);
        if(!$job)return 'Bu müşteride pazaryeri API bağlantısı henüz kurulmamış. Önce bağlantıyı tamamlayın.';
        if($job['error'])return 'Katalog doğrulanamadı: '.$job['error'];
        if(!$job['last_success']||new \DateTimeImmutable($job['last_success'])<new \DateTimeImmutable($link['requested_at'])){
            if((int)$job['page_no']===0&&(int)$job['partition_no']===0)$this->db->run('UPDATE inventory_catalog_jobs SET next_run=0 WHERE company_id=? AND provider=?',[$company,$slug]);
            return null;
        }
        $target=(new InventoryStock($this->db))->product($company,$link['product_key']);
        $code=InventoryLiveSkuIdentity::normalize($link['match_code']?:$target['sku']);$candidates=[];
        if($code===''||$code!==InventoryLiveSkuIdentity::normalize($target['sku']))
            return 'Seçilen pazaryerindeki stok kodu ürünün stok koduyla aynı olmalı.';
        foreach($this->db->all("SELECT l.product_key,l.sku,l.observed_at,p.quantity FROM inventory_listings l JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key=l.product_key WHERE l.company_id=? AND l.provider=? AND l.remote_id NOT LIKE 'order-sku:%'",[$company,$link['provider']]) as $source){
            if(new \DateTimeImmutable($source['observed_at'])<new \DateTimeImmutable($link['requested_at']))continue;
            if(InventoryLiveSkuIdentity::normalize($source['sku'])===$code)$candidates[$source['product_key']]=$source;
        }
        if(!$candidates)return 'Seçilen pazaryerinde eşleşen stok kodu bulunamadı.';
        if(count($candidates)>1)return 'Birden fazla ürün eşleşiyor. Bu pazaryerindeki kesin stok kodunu girin.';
        $source=reset($candidates);
        if($source['quantity']!==null&&$source['product_key']!==$link['product_key'])return 'Eşleşen ürünün ayrı bir stok kaydı zaten var. Mevcut ürünü kullanın.';
        if($this->db->one("SELECT request_id FROM inventory_commands WHERE company_id=? AND product_key=? AND state='pending'",[$company,$source['product_key']]))return 'Eşleşen ürünün bekleyen stok işlemi var. İşlem tamamlandıktan sonra yeniden deneyin.';
        $other=$this->db->one('SELECT product_key FROM inventory_links WHERE company_id=? AND provider=? AND source_product=?',[$company,$link['provider'],$source['product_key']]);
        if($other&&$other['product_key']!==$link['product_key'])return 'Bu pazaryeri ürünü başka bir stok ürününe bağlı.';
        $started=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        $this->db->run("UPDATE inventory_links SET state='active',source_product=?,started_at=?,reason='' WHERE company_id=? AND product_key=? AND provider=?",[$source['product_key'],$started,$company,$link['product_key'],$link['provider']]);
        $this->db->run('DELETE FROM inventory_pending_lines WHERE company_id=? AND provider=? AND product_key=?',[$company,$link['provider'],$source['product_key']]);
        return null;
    }
}

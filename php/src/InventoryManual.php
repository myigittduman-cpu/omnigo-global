<?php
declare(strict_types=1);
namespace Omnigo;

/** A manually entered product uses the same merchant SKU as subsequent catalog offers. */
final class InventoryManual
{
    public function __construct(private Database $db){}
    public function create(int $company,array $input,int $actor):array
    {
        $fields=[];
        foreach(['title'=>500,'sku'=>180,'barcode'=>32,'brand'=>180,'model'=>180] as $field=>$limit){
            if(!is_string($input[$field]??''))throw new \InvalidArgumentException('Ürün bilgilerini kontrol edin.');
            $fields[$field]=trim($input[$field]??'');
            if(mb_strlen($fields[$field])>$limit)throw new \InvalidArgumentException('Ürün bilgisi çok uzun.');
        }
        if($fields['title']===''||$fields['sku']==='')throw new \InvalidArgumentException('Ürün adı ve stok kodu gereklidir.');
        if($fields['barcode']!==''&&!InventoryCatalog::validGtin($fields['barcode']))throw new \InvalidArgumentException('Barkod doğrulanamadı. Barkod yoksa alanı boş bırakın.');
        $amount=$input['quantity']??null;$request=$input['requestId']??'';
        if(!is_int($amount)||abs($amount)>100000000||!is_string($request)||!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$request))throw new \InvalidArgumentException('Başlangıç stoğunu kontrol edin.');
        $connect=$input['connect']??null;
        if(!is_bool($connect))throw new \InvalidArgumentException('Takip şeklini seçin.');
        $providers=InventoryMatching::validateSelection($connect?($input['providers']??[]):[], $connect);
        $key=hash('sha256','manual:'.$request);$hash=hash('sha256',json_encode([$fields,$amount,$connect,$providers,$actor],JSON_THROW_ON_ERROR));
        return $this->db->transaction(function()use($company,$fields,$amount,$key,$hash,$actor,$connect,$providers){
            (new InventorySettings($this->db))->requireStore($company);
            $job=$this->db->one('SELECT enabled FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))throw new \OutOfBoundsException('Müşteri bulunamadı.');
            if(!$job||!(int)$job['enabled'])throw new \DomainException('Bu müşterinin stok bölümü henüz etkin değil.');
            $old=$this->db->one('SELECT request_hash FROM inventory_manual_products WHERE company_id=? AND product_key=?',[$company,$key]);
            if($old){if(!hash_equals($old['request_hash'],$hash))throw new \DomainException('İşlem anahtarı farklı bir ürün için kullanılmış.');return ['key'=>$key,'replayed'=>true];}
            $identity=InventoryLiveSkuIdentity::load($this->db,$company);
            if($this->db->one('SELECT product_key FROM inventory_products WHERE company_id=? AND product_key=?',[$company,$identity->key($fields['sku'])]))
                throw new \DomainException('Bu stok kodunda kayıtlı ürün var. Mevcut ürünü kullanın.');
            $now=gmdate('c');
            $this->db->run('INSERT INTO inventory_products(company_id,product_key,title,sku,barcode,image_url,updated) VALUES(?,?,?,?,?,?,?)',[$company,$key,$fields['title'],$fields['sku'],$fields['barcode'],'',$now]);
            InventoryProductPosition::remember($this->db,$company,$key);
            $this->db->run('INSERT INTO inventory_manual_products(company_id,product_key,brand,model,request_hash) VALUES(?,?,?,?,?)',[$company,$key,$fields['brand'],$fields['model'],$hash]);
            $product=(new InventoryStock($this->db))->product($company,$key);
            (new InventoryStock($this->db))->write($company,$product,hash('sha256','manual-create:'.$key),'manual_create',$amount,$amount,$hash,$actor,'Manuel ürün başlangıç stoğu');
            if((new InventorySettings($this->db))->preferences($company)['mode']==='horoz'){
                $this->db->run('INSERT INTO inventory_shared_stock(company_id,product_key,adjustment,manual_quantity) VALUES(?,?,0,?)',[$company,$key,$amount]);
                (new InventoryChangeEvents($this->db))->record($company,hash('sha256','manual-create:'.$key),$key,$actor,['events'=>['inventory_adjusted'],'stockMode'=>'horoz','productTitle'=>$fields['title'],'sku'=>$fields['sku'],'quantityBefore'=>null,'quantity'=>$amount,'delta'=>$amount,'movementKind'=>'manual_create']);
            }
            (new InventoryImageQueue($this->db))->enqueue($company,$key);
            if($connect)(new InventoryMatching($this->db))->enqueue($company,$key,$providers);
            return ['key'=>$key,'quantity'=>$amount,'replayed'=>false];
        });
    }
}

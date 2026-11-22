<?php
declare(strict_types=1);
namespace Omnigo;

final class InventoryCommands
{
    public function __construct(private Database $db){}
    public function submit(int $company,array $input,int $actor):array
    {
        $key=$input['productKey']??'';$request=$input['requestId']??'';$mode=$input['mode']??'';$amount=$input['amount']??null;$version=$input['version']??null;
        $stockMode=(new InventorySettings($this->db))->preferences($company)['mode'];$sourceVersion=$input['sourceVersion']??null;
        if($sourceVersion!==null&&(!is_string($sourceVersion)||!preg_match('/^[a-f0-9]{64}$/D',$sourceVersion)))throw new \InvalidArgumentException('Canlı stok bilgisini yenileyin.');
        $defaultNote=match($mode){'store_sale'=>$stockMode==='horoz'?'Ortak stoktan mağaza satışı':'Mağaza satışı','store_return'=>$stockMode==='horoz'?'Ortak stoğa mağaza satış iadesi':'Mağaza satış iadesi','initialize'=>$stockMode==='horoz'?'Başlangıç ortak stok':'Başlangıç mağaza stoğu','set'=>$stockMode==='horoz'?'Ortak stok güncellemesi':'Mağaza / depo stok güncellemesi','add'=>'Stok girişi','subtract'=>'Stok düzeltmesi',default=>''};
        $note=$input['note']??$defaultNote;
        if(!is_string($note))throw new \InvalidArgumentException('Stok işlem bilgisi geçersiz.');
        $note=trim($note);if($note==='')$note=$defaultNote;
        if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!is_string($request)||!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$request)||!in_array($mode,['initialize','set','add','subtract','store_sale','store_return'],true)||!is_int($amount)||abs($amount)>100000000||($amount<0&&in_array($mode,['add','subtract'],true))||(in_array($mode,['store_sale','store_return'],true)&&$amount<1)||!is_int($version)||$version<1||mb_strlen($note)<3||strlen($note)>500)throw new \InvalidArgumentException('Miktarı ve işlem açıklamasını kontrol edin.');
        $hash=hash('sha256',json_encode([$key,$mode,$amount,$version,$note,$actor],JSON_THROW_ON_ERROR));
        if($stockMode==='horoz')$hash=hash('sha256',$hash.':horoz:'.$sourceVersion);
        return TransactionRetry::run($this->db,function()use($company,$key,$request,$mode,$amount,$version,$note,$actor,$hash,$stockMode,$sourceVersion){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if((new InventorySettings($this->db))->preferences($company)['mode']!==$stockMode)throw new \DomainException('Stok modeli değişti. Sayfayı yenileyin.');
            (new InventorySettings($this->db))->requireStore($company);
            $job=$this->db->one('SELECT * FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
            if(!$job||!(int)$job['enabled'])throw new \DomainException('Bu müşterinin stok bağlantısı henüz etkin değil.');
            $old=$this->db->one('SELECT request_hash,state FROM inventory_commands WHERE company_id=? AND request_id=?',[$company,$request]);
            if($old){if(!hash_equals($old['request_hash'],$hash))throw new \DomainException('İşlem anahtarı çakıştı.');return ['requestId'=>$request,'state'=>$old['state']];}
            $product=(new InventoryStock($this->db))->product($company,$key);
            (new InventoryVisibility($this->db))->requireVisible($company,$key);
            if((int)$product['version']!==$version)throw new \DomainException('Stok güncellendi. Güncel miktarı kontrol ederek tekrar kaydedin.');
            if($this->db->one("SELECT request_id FROM inventory_commands WHERE company_id=? AND product_key=? AND state='pending'",[$company,$key]))throw new \DomainException('Bu ürün için bekleyen stok kaydı var.');
            $now=gmdate('c');
            $this->db->run('INSERT INTO inventory_commands(company_id,request_id,product_key,expected_version,mode,amount,actor_id,note,request_hash,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$company,$request,$key,$version,$mode,$amount,$actor,$note,$hash,$now,$now]);
            $this->db->run('INSERT INTO inventory_command_context(company_id,request_id,stock_mode,source_version) VALUES(?,?,?,?)',[$company,$request,$stockMode,$sourceVersion]);
            $this->db->run('UPDATE inventory_jobs SET next_run=0 WHERE company_id=?',[$company]);
            return ['requestId'=>$request,'state'=>'pending'];
        });
    }
    public function process(int $company,string $lease,?string $request=null):void
    {
        foreach($this->db->all("SELECT * FROM inventory_commands WHERE company_id=? AND state='pending'".($request!==null?' AND request_id=?':'')." ORDER BY created,request_id LIMIT 20",[$company,...($request!==null?[$request]:[])]) as $row){
            try{
                $context=$this->db->one('SELECT stock_mode,source_version FROM inventory_command_context WHERE company_id=? AND request_id=?',[$company,$row['request_id']]);
                $current=(new InventorySettings($this->db))->preferences($company)['mode'];
                if(($context&&$context['stock_mode']!==$current)||(!$context&&$current==='horoz'))throw new \DomainException('Stok modeli değişti. Güncel tablo üzerinden yeniden kaydedin.');
                $result=(new InventoryStock($this->db))->change($company,$row['product_key'],$row['request_id'],(int)$row['expected_version'],$row['mode'],(int)$row['amount'],(int)$row['actor_id'],$row['note'],$lease,$context['source_version']??null);$state='done';
            }
            catch(\DomainException|\InvalidArgumentException|\OutOfBoundsException $e){$result=['error'=>$e->getMessage()];$state='failed';}
            $this->db->run('UPDATE inventory_commands SET state=?,result=?,updated=? WHERE company_id=? AND request_id=?',[$state,json_encode($result,JSON_THROW_ON_ERROR),gmdate('c'),$company,$row['request_id']]);
        }
    }
}

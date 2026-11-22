<?php
declare(strict_types=1);
namespace Omnigo;

final class ActiveMarketplaceListingRoutes
{
    public function __construct(private Database $db,private Security $security) {}

    public function handle(string $path,string $method,array $user): void
    {
        if($path!=='/api/inventory/active-marketplace-listings')return;
        Security::admin($user);
        $company=$this->security->company($user,$_GET['company']??null);
        $pilot=MarketplaceStockPilot::load();
        if(isset($_GET['model'])){
            if($method==='GET')Http::json(['enabled'=>MarketplaceStockJob::allowed($this->db,$pilot,$company)]);
            if($method!=='POST')Http::fail(405,'Bu işlem desteklenmiyor.');
            $enabled=Http::body()['enabled']??null;
            if(!is_bool($enabled))Http::fail(400,'Model seçimi geçersiz.');
            MarketplaceStockSchema::installAccess($this->db);
            TransactionRetry::run($this->db,function()use($company,$enabled,$user){
                $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                $this->db->run('DELETE FROM marketplace_model_access WHERE company_id=?',[$company]);
                $this->db->run('INSERT INTO marketplace_model_access(company_id,enabled) VALUES(?,?)',[$company,(int)$enabled]);
                $this->db->run('INSERT INTO audit(user_id,company_id,action,after_value,created) VALUES(?,?,?,?,?)',[(int)$user['id'],$company,'Pazaryeri stok ve fiyat modeli',json_encode(['enabled'=>$enabled]),gmdate('c')]);
            });
            Http::json(['enabled'=>$enabled]);
        }
        if(!MarketplaceStockJob::allowed($this->db,$pilot,$company))Http::fail(403,'Bu mağazada etkin satış listesi açık değil.');
        if($method!=='GET')Http::fail(405,'Bu liste yalnızca görüntülenebilir.');
        if(isset($_GET['productKey'])){
            $key=$_GET['productKey'];
            if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))Http::fail(400,'Ürün kimliği geçersiz.');
            $targets=(new ActiveMarketplaceListings($this->db))->writableProductTargets($company,$key);
            foreach($targets as &$target){unset($target['remoteId'],$target['catalogSku']);}
            unset($target);
            $latest=null;
            if(IntegrationImportSchema::tableAvailable($this->db,'marketplace_price_jobs','state')){
                $latest=$this->db->one('SELECT request_id,price FROM marketplace_price_jobs WHERE company_id=? AND product_key=? ORDER BY created DESC,request_id DESC LIMIT 1',[$company,$key]);
                if($latest)$latest['results']=(new MarketplacePriceQueue($this->db))->results($company,$latest['request_id']);
            }
            $coverage=(new MarketplaceListingCoverage($this->db))->unavailable($company,$key,$targets);
            Http::json(['targets'=>$targets,'latestPrice'=>$latest,'unavailable'=>$coverage]);
        }
        $query=$_GET['query']??'';$provider=$_GET['provider']??'';$page=$_GET['page']??'0';
        if(!is_string($query)||mb_strlen($query)>180||!is_string($provider)||!is_scalar($page)||!ctype_digit((string)$page)||(int)$page>100000)
            Http::fail(400,'Arama, pazaryeri ve sayfa bilgilerini kontrol edin.');
        try{$label=InventoryMarketplaceFilter::label($provider);}
        catch(\InvalidArgumentException){Http::fail(400,'Pazaryeri seçimini kontrol edin.');}
        Http::json((new ActiveMarketplaceListings($this->db))->page($company,trim($query),$label,(int)$page));
    }
}

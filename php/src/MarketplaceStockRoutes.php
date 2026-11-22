<?php
declare(strict_types=1);
namespace Omnigo;

final class MarketplaceStockRoutes
{
    public function __construct(private Database $db,private Security $security){}
    public function handle(string $path,string $method,array $user):void
    {
        if(!in_array($path,['/api/inventory/marketplace-stock','/api/inventory/active-marketplace-stock'],true))return;
        Security::admin($user);$company=$this->security->company($user,$_GET['company']??null);
        $pilot=$path==='/api/inventory/active-marketplace-stock'?MarketplaceStockPilot::load():null;
        if($pilot&&!MarketplaceStockJob::allowed($this->db,$pilot,$company))Http::fail(403,'Bu mağaza için pazaryeri stok yönetimi etkin değil.');
        $queue=new MarketplaceStockQueue($this->db,new MarketplaceStockPolicy($this->db),$pilot);
        try{
            if($method==='GET'&&$pilot&&isset($_GET['priceRequest'])){
                Http::json(['results'=>(new MarketplacePriceQueue($this->db))->results($company,(string)$_GET['priceRequest'])]);
            }
            if($method==='GET')Http::json($queue->get($company,(string)($_GET['requestId']??''),$user));
            if($method==='POST'){
                $input=Http::body();
                if($pilot&&($input['action']??null)==='price')Http::json(['results'=>(new MarketplacePriceQueue($this->db))->add($company,$input,$user)],202);
                if($pilot&&($input['action']??null)==='group'){
                    $targets=$input['targets']??null;$product=$input['productKey']??null;
                    if(($input['confirmed']??false)!==true||!is_array($targets)||!array_is_list($targets)||!$targets||count($targets)>100||!is_string($product))
                        throw new \InvalidArgumentException('Gönderilecek ürün ve ilanlar doğrulanamadı.');
                    $results=[];
                    foreach($targets as $target){
                        if($target instanceof \stdClass)$target=get_object_vars($target);
                        if(!is_array($target))throw new \InvalidArgumentException('İlan bilgisi geçersiz.');
                        try{
                            $job=$queue->submit($company,array_merge($target,['productKey'=>$product,'quantity'=>$input['quantity']??null,'confirmed'=>true]),$user);
                            $results[]=['state'=>$job['state'],'requestId'=>$job['requestId']];
                        }catch(\InvalidArgumentException|\DomainException|\OutOfBoundsException $error){$results[]=['error'=>$error->getMessage()];}
                    }
                    Http::json(['results'=>$results],202);
                }
                if(($input['action']??'')==='retry'){
                    if(($input['confirmed']??false)!==true)throw new \InvalidArgumentException($pilot?'Önceki stok işleminin sonucunu kontrol etmek için onay verin.':'Aynı stok adedini yeniden göndermek için onay verin.');
                    Http::json($queue->retry($company,(string)($input['requestId']??''),$user),202);
                }
                Http::json($queue->submit($company,$input,$user),202);
            }
            Http::fail(405,'Bu pazaryeri stok işlemi desteklenmiyor.');
        }catch(\InvalidArgumentException $error){Http::fail(400,$error->getMessage());}
        catch(\OutOfBoundsException $error){Http::fail(404,$error->getMessage());}
        catch(\DomainException $error){Http::fail(409,$error->getMessage());}
    }
}

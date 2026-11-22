<?php
declare(strict_types=1);
namespace Omnigo;

/** Legacy client IDs cannot contain a colon. A pilot request permanently records its destination. */
final class MarketplaceStockJob
{
    public const CHANNELS=['hepsiburada'=>'Hepsiburada','n11'=>'n11','trendyol'=>'Trendyol'];

    public static function origin(string $request): ?string
    {
        if(!str_starts_with($request,'pilot:'))return null;
        $parts=explode(':',$request,3);
        if(count($parts)!==3||!isset(self::CHANNELS[$parts[1]]))throw new \DomainException('Stok işleminin pazaryeri doğrulanamadı.');
        return $parts[1];
    }

    public static function allowed(Database $db,MarketplaceStockPilot $pilot,int $company):bool
    {
        if(IntegrationImportSchema::tableAvailable($db,'marketplace_model_access','enabled')){
            $override=$db->one('SELECT enabled FROM marketplace_model_access WHERE company_id=?',[$company]);
            if($override)return (bool)$override['enabled'];
        }
        $row=$db->one('SELECT id,name FROM companies WHERE id=?',[$company]);
        return $row&&$pilot->enabled($row);
    }

    public static function gateway(string $provider): MarketplaceStockGateway
    {
        return match($provider){
            'hepsiburada'=>new HepsiburadaStockGateway(),
            'n11'=>new N11StockGateway(),
            'trendyol'=>new TrendyolStockGateway(),
            default=>throw new \DomainException('Bu pazaryerinde stok gönderimi desteklenmiyor.'),
        };
    }

    public static function configuration(Database $db,IntegrationVault $vault,int $company,string $provider): ?array
    {
        $config=$vault->get($company,$provider);
        if($provider==='n11'&&is_array($config)){
            $record=$db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_n11'",[$company]);
            $state=json_decode($record['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
            $seller=$state['sellerId']??null;
            if($seller===null&&!empty($config['enabled'])&&!empty($config['apiKey'])&&!empty($config['apiSecret'])){
                // A new merchant can list products before receiving a first order.
                $auth=N11Credentials::fromArray($config);
                $catalog=(new StockGatewayHttp(null))->request('GET','https://api.n11.com/ms/product-query?sender=SELLER&page=0&size=2',
                    ['appKey: '.$auth->apiKey,'appSecret: '.$auth->apiSecret],null,time()+6);
                $ids=[];
                foreach($catalog['content']??[] as $row){
                    if(($row['sender']??null)!=='SELLER')throw new \DomainException('n11 satıcı deposu doğrulanamadı.');
                    $ids[]=StockGatewayReceipt::seller($row['sellerId']??null);
                }
                if(count(array_unique($ids))===1)$seller=$ids[0];
            }
            try{$config['sellerId']=StockGatewayReceipt::seller($seller);}
            catch(\DomainException){throw new \DomainException('n11 satıcı kimliği henüz sipariş aktarımından doğrulanamadı. Kimlik doğrulandıktan sonra stok gönderilebilir.');}
        }
        return $config;
    }
}

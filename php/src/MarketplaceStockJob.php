<?php
declare(strict_types=1);
namespace Omnigo;

/** Legacy client IDs cannot contain a colon. A pilot request permanently records its destination. */
final class MarketplaceStockJob
{
    public const CHANNELS=['hepsiburada'=>'Hepsiburada','n11'=>'n11','trendyol'=>'Trendyol','pazarama'=>'Pazarama'];

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

    public static function pazaramaEnabled(Database $db,MarketplaceStockPilot $pilot,int $company):bool
    {
        $row=$db->one('SELECT id,name FROM companies WHERE id=?',[$company]);
        return $row&&$pilot->pazaramaEnabled($row);
    }

    public static function read(object $gateway,array $config,string $sku,string $remoteId,int $deadline):array
    {
        return $gateway instanceof PazaramaStockGateway?$gateway->read($config,$sku,$deadline,$remoteId):$gateway->read($config,$sku,$deadline);
    }

    public static function gateway(string $provider,?Database $db=null,?int $company=null): MarketplaceStockGateway
    {
        return match($provider){
            'hepsiburada'=>new HepsiburadaStockGateway(),
            'n11'=>new N11StockGateway(),
            'trendyol'=>new TrendyolStockGateway(),
            'pazarama'=>self::pazaramaGateway($db,$company),
            default=>throw new \DomainException('Bu pazaryerinde stok gönderimi desteklenmiyor.'),
        };
    }

    private static function pazaramaGateway(?Database $db,?int $company): PazaramaStockGateway
    {
        if(!$db||!$company)throw new \DomainException('Pazarama mağaza kapsamı doğrulanamadı.');
        return new PazaramaStockGateway(writeGate:static function(int $deadline,callable $send)use($db,$company){
            $gate=new ProviderRequestGate($db);
            if(!IntegrationImportSchema::tableAvailable($db,'provider_request_gates','company_id'))throw new MarketplaceStockFailure(retryable:true);
            try{$token=$gate->acquire($company,'pazarama',$deadline);}
            catch(ProviderRequestDeferred $error){throw new MarketplaceStockFailure(retryable:true,retryAfter:max(10,$error->retryAt-time()));}
            $cooldown=10;
            try{return $send();}
            catch(MarketplaceStockFailure $error){
                if($error->retryable)$cooldown=max($cooldown,$error->retryAfter);
                throw $error;
            }finally{$gate->release($company,'pazarama',$token,cooldown:$cooldown);}
        });
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

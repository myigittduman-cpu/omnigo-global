<?php
declare(strict_types=1);
namespace Omnigo;

/** Existing official order readers and mappers; no seller-side write operations. */
final class RecentOrderReader
{
    public function __construct(private ?N11ReadClient $n11=null,private ?PazaramaAuthClient $auth=null,private ?PazaramaReadClient $pazarama=null,private ?MarketplaceReadClient $marketplace=null){}
    public function page(string $provider,array $config,array $scan,int $deadline): array
    {
        $zone=new \DateTimeZone('Europe/Istanbul');$from=(new \DateTimeImmutable('@'.$scan['from']))->setTimezone($zone);$through=(new \DateTimeImmutable('@'.$scan['through']))->setTimezone($zone);
        if($provider==='n11'){
            $result=($this->n11??new N11ReadClient())->packages(N11Credentials::fromArray($config),$scan['from']*1000,$scan['through']*1000,$scan['page'],$deadline);
            $raw=$result['content'];$complete=$scan['page']+1>=max(1,$result['totalPages']);
        }elseif($provider==='pazarama'){
            $token=($this->auth??new PazaramaAuthClient())->authenticate(PazaramaCredentials::fromArray($config),$deadline);
            PazaramaSellerIdentity::verify($token['accessToken'],$config['sellerId']);
            $raw=($this->pazarama??new PazaramaReadClient())->orders($token['accessToken'],$from->format('Y-m-d\TH:i'),$through->format('Y-m-d\TH:i'),$scan['page'],$deadline);$complete=count($raw)<20;
        }elseif($provider==='idefix'){
            $result=($this->marketplace??new MarketplaceReadClient())->orders($provider,$config,$from->format('Y-m-d H:i:s'),$through->format('Y-m-d H:i:s'),$scan['page'],$deadline);$raw=$result['items'];$complete=$result['complete'];
        }else throw new SyncFailure(422);
        if(!$complete&&!$raw)throw new SyncFailure(422);
        $items=[];$seller=null;
        foreach($raw as $value){
            if($provider==='idefix'){$mapped=IdefixOrderMapper::map($value);$items[]=['unit'=>$mapped['order'],'delivery'=>$mapped['delivery']];continue;}
            $delivery=DeliveryMapper::$provider($value);
            $delivery['details']=OrderDetailMapper::$provider($value,$delivery);$delivery['shipmentItems']=ShipmentOrderMapper::$provider($value,$delivery);
            $unit=$provider==='n11'?N11SalesMapper::package($value):PazaramaSalesMapper::order($value);
            if($provider==='n11'){if($seller!==null&&$seller!==$unit['sellerId'])throw new SyncFailure(422);$seller=$unit['sellerId'];}
            $items[]=['unit'=>$unit,'delivery'=>$delivery];
        }
        return ['items'=>$items,'complete'=>$complete,'sellerId'=>$seller];
    }
}

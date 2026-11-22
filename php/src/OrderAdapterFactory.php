<?php
declare(strict_types=1);
namespace Omnigo;

final class OrderAdapterFactory
{
    public static function create(Database $db,IntegrationVault $vault,DeliveryRepository $deliveries): array
    {
        $adapters=['Trendyol'=>new TrendyolSyncAdapter($db,$vault,$deliveries),'PTT AVM'=>new PttavmSyncAdapter($db,$vault,$deliveries),'Koçtaş'=>new KoctasAdapter($db,$vault,deliveries:$deliveries),'Hepsiburada'=>new HepsiburadaOrderAdapter($db,$vault,$deliveries)];
        foreach(['n11'=>new N11SyncAdapter($db,$vault,deliveries:$deliveries),'Pazarama'=>new PazaramaSyncAdapter($db,$vault,deliveries:$deliveries),'idefix'=>new IdefixSyncAdapter($db,$vault,$deliveries)] as $name=>$history){
            $provider=$name==='Pazarama'?'pazarama':$name;
            $adapters[$name]=new FrequentOrderAdapter($db,$vault,$provider,$deliveries,new FinancialRefreshBatch($db,$provider,$history));
        }
        return $adapters;
    }
}

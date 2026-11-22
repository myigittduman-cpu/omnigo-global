<?php
declare(strict_types=1);
namespace Omnigo;

final class RecentOrderSummary
{
    public static function combine(string $provider,array $packages): ?array
    {
        if($provider==='n11'){
            $active=[];$split=[];
            foreach($packages as $package)foreach($package['products'] as $product){
                $key=$product['lineId'];
                if($package['status']==='Unpacked'||$product['status']==='Unpacked')$split[$key]=true;
                else $active[$key]=true;
            }
            // Keep pending packages across API pages without blocking unrelated new orders.
            if(array_diff_key($split,$active))return null;
            return N11SalesMapper::orders($packages,[],'1970-01-01T00:00:00Z','9999-12-31T23:59:59Z')[0]??null;
        }
        if($provider==='idefix'){
            $seen=[];
            foreach($packages as $package)if($package['orders'])foreach($package['products'] as $product){
                if(isset($seen[$product['lineId']]))return null;
                $seen[$product['lineId']]=true;
            }
            return IdefixOrderMapper::combine($packages)[0]??null;
        }
        if($provider==='pazarama')return $packages[array_key_last($packages)];
        throw new SyncFailure(422);
    }
}

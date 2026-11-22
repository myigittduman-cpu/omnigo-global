<?php
declare(strict_types=1);
namespace Omnigo;

/** Listing feeds are hints, never financial snapshots or evidence of deletion. */
final class HepsiburadaOrderDiscovery
{
    public const FEEDS=['open','packages','cancelled','shipped','delivered','undelivered'];
    public static function numbers(array $items,string $merchant): array
    {
        $numbers=[];
        foreach($items as $item){
            if(!is_array($item))throw new SyncFailure(422);
            $lines=isset($item['items'])?$item['items']:[$item];
            if(!is_array($lines)||!array_is_list($lines)||!$lines)throw new SyncFailure(422);
            foreach($lines as $line){
                if(!is_array($line)||strtolower((string)($line['merchantId']??$line['MerchantId']??$item['merchantId']??''))!==$merchant)throw new SyncFailure(422);
                $candidates=$line['OrderNumbers']??[$line['orderNumber']??$line['OrderNumber']??$item['orderNumber']??''];
                if(!is_array($candidates)||!$candidates)throw new SyncFailure(422);
                foreach($candidates as $number){$number=(string)$number;if(!preg_match('/^[0-9]{1,30}$/D',$number))throw new SyncFailure(422);$numbers[$number]=true;}
            }
        }
        return array_map('strval',array_keys($numbers));
    }
    public static function remember(array $state,string $feed,int $offset,array $page,string $merchant): array
    {
        if(!in_array($feed,self::FEEDS,true))throw new SyncFailure(422);
        $numbers=self::numbers($page['items'],$merchant);
        // Revisit known ready packages once to attach verified labels to existing orders.
        $hash=hash('sha256',(in_array($feed,['packages','shipped'],true)?'shipping-labels-v1:':'').json_encode($page['items'],JSON_THROW_ON_ERROR));
        if(($state['pageHashes'][$feed][$offset]??null)!==$hash){
            foreach($numbers as $number)$state['pendingOrders'][$number]=true;
            if(count($state['pendingOrders']??[])>10000)throw new SyncFailure(503,60);
            $state['pageHashes'][$feed][$offset]=$hash;
        }
        // The provider's offset counts returned rows. A partial page with more
        // records must resume immediately after the last row actually read.
        $state['offsets'][$feed]=$page['hasMore']?$offset+count($page['items']):0;
        if(!$page['hasMore']){
            $state['feedCompletedAt'][$feed]=gmdate('c');
            foreach(array_keys($state['pageHashes'][$feed]) as $saved)if($saved>$offset)unset($state['pageHashes'][$feed][$saved]);
        }
        return $state;
    }
}

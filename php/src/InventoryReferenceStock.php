<?php
declare(strict_types=1);
namespace Omnigo;

/** Selects one fresh source for a shared pool; quantities are never added together. */
final class InventoryReferenceStock
{
    private const PRIORITY=['Hepsiburada','n11','Koçtaş','PTT AVM','Pazarama','idefix','Trendyol'];

    public static function resolve(array $snapshots): array
    {
        $current=array_values(array_filter($snapshots,static fn(array $s):bool=>
            ($s['health']??'')==='current' && isset($s['quantity'])));
        usort($current,static function(array $a,array $b):int{
            $rank=static function(array $s):int{
                if(($s['saleActive']??null)===true)return (int)$s['quantity']>0?0:1;
                return ($s['saleActive']??null)===false?3:2;
            };
            $priority=static function(array $s):int{
                $index=array_search($s['provider']??'',self::PRIORITY,true);
                return $index===false?count(self::PRIORITY):$index;
            };
            return ($rank($a)<=>$rank($b)) ?: ($priority($a)<=>$priority($b))
                ?: strcmp($a['provider']??'',$b['provider']??'')
                ?: strcmp($a['sku']??'',$b['sku']??'')
                ?: (strtotime($b['observedAt']??'')<=>strtotime($a['observedAt']??''))
                ?: ((int)$a['quantity']<=>(int)$b['quantity']);
        });
        $reference=$current[0]??null;
        return ['reference'=>$reference,'different'=>count(array_unique(array_map(static fn(array $s):int=>(int)$s['quantity'],$current)))>1];
    }
}

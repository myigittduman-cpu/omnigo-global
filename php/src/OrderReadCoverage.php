<?php
declare(strict_types=1);
namespace Omnigo;

/** Completed, verified order windows can serve a pending request without a second API call. */
final class OrderReadCoverage
{
    public static function remember(array &$state,int $from,int $through): void
    {
        if($from<=0||$through<$from)return;
        $windows=$state['orderReadCoverage']??[];
        $windows[]=['from'=>$from,'through'=>$through,'observedAt'=>time()];
        $state['orderReadCoverage']=array_slice($windows,-120);
    }

    public static function covers(array $state,int $from,int $through,int $requested): bool
    {
        $windows=array_filter($state['orderReadCoverage']??[],static fn(array $entry):bool=>
            $entry['observedAt']>=$requested&&$entry['through']>=$from&&$entry['from']<=$through);
        usort($windows,static fn(array $a,array $b):int=>$a['from']<=>$b['from']);
        $covered=$from-1;
        foreach($windows as $window){
            if($window['from']>$covered+1)return false;
            $covered=max($covered,$window['through']);
            if($covered>=$through)return true;
        }
        return false;
    }
}

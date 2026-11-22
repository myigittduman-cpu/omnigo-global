<?php
declare(strict_types=1);
namespace Omnigo;

/** Catch up from the committed watermark in bounded, overlapping API windows. */
final class RecentOrderWindow
{
    public static function next(string $provider,array $recent,array $config,int $now): array
    {
        $from=(int)($recent['watermark']??($now-3600))-600;
        if(!empty($config['startDate'])){
            try{$start=(new \DateTimeImmutable($config['startDate'],new \DateTimeZone('Europe/Istanbul')))->getTimestamp();}
            catch(\Exception){throw new SyncFailure(422);}
            $from=max($from,$start);
        }
        // A stale watermark must never be moved forward merely to satisfy a provider's range limit.
        $from=min($from,$now-1);
        $through=min($now,$from+13*86400);
        // Pazarama accepts minute precision and an exclusive upper bound.
        if($provider==='pazarama')$through=(intdiv($through,60)+1)*60;
        return ['from'=>$from,'through'=>$through,'page'=>$provider==='n11'?0:1];
    }
}

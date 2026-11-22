<?php
declare(strict_types=1);
namespace Omnigo;

final class SyncFreshness
{
    public const TARGET_SECONDS=120;
    public const ALERT_SECONDS=300;
    public static function fast(string $provider): bool{return in_array($provider,['n11','idefix','pazarama','koctas','hepsiburada','pttavm','trendyol'],true);}
    public static function age(?string $last,int $now): ?int
    {
        $stamp=$last?strtotime($last):false;return $stamp===false?null:max(0,$now-$stamp);
    }
}

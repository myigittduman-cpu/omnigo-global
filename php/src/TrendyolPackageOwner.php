<?php
declare(strict_types=1);
namespace Omnigo;

/** Verify every product line before any package on the page can be stored. */
final class TrendyolPackageOwner
{
    public static function verify(array $package,string $seller): void
    {
        $lines=$package['lines']??null;
        if(!is_array($lines)||!array_is_list($lines)||!$lines)throw new SyncFailure(422);
        $needsLegacyOwner=false;
        foreach($lines as $line){
            if(!is_array($line))throw new SyncFailure(422);
            if(!array_key_exists('sellerId',$line)){$needsLegacyOwner=true;continue;}
            // Explicit invalid/foreign identities must never fall back to a matching package ID.
            if(!self::matches($line['sellerId'],$seller))throw new SyncFailure(403);
        }
        // Older responses without line identities retain the original package-owner check.
        if($needsLegacyOwner&&!self::matches($package['supplierId']??null,$seller))throw new SyncFailure(403);
    }

    private static function matches(mixed $value,string $seller): bool
    {
        return (is_int($value)||is_string($value))
            &&preg_match('/^[1-9][0-9]{0,15}$/D',(string)$value)===1
            &&(string)$value===$seller;
    }
}

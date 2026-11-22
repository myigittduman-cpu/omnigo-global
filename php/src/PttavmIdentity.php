<?php
declare(strict_types=1);
namespace Omnigo;

/** PTT's order API name is the delivery recipient, not the account holder shown in its panel. */
final class PttavmIdentity
{
    public static function display(array $package): array
    {
        if(($package['provider']??'')!=='PTT AVM'||isset($package['manualCorrections']['buyerName']))return $package;
        if(isset($package['details']))$package['details']=[...$package['details'],'customerName'=>'','customerNameUnavailable'=>true];
        else $package=[...$package,'customerName'=>'','customerNameUnavailable'=>true];
        return $package;
    }
}

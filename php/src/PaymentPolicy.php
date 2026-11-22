<?php
declare(strict_types=1);
namespace Omnigo;

/** Payouts are administrator declarations; marketplace imports are retained as archive only. */
final class PaymentPolicy
{
    public static function automaticJob(string $provider): bool{return str_ends_with($provider,' Hakediş');}
    public static function disableAutomaticJobs(Database $db): void
    {
        $message='Hakedişler yönetici tarafından elle yönetilir.';
        $db->run("UPDATE jobs SET state='blocked',error=?,attempts=0,lease_token=NULL,lease_until=NULL WHERE provider LIKE '% Hakediş' AND state<>'running' AND (state<>'blocked' OR error IS NULL OR error<>?)",[$message,$message]);
    }
    public static function decorate(array $payment): array
    {
        $payment['legacyManual']=false;
        $payment['excludedFromTotals']=!empty($payment['integration']);
        return $payment;
    }
    public static function missing(array $payments,array $channels): array
    {
        return [];
    }
}

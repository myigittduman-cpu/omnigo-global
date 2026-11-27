<?php
declare(strict_types=1);
namespace Omnigo;

/** A recovered marketplace observation must never supersede a deliberate edit. */
final class UpcomingPayoutReviewPolicy
{
    public static function hasEdits(Database $db,int $company,array $items): bool
    {
        if(!PazaramaCommissionStore::available($db))return false;
        $stored=(new FinanceStore($db))->state($company,'payout_adjustments');
        $changes=json_decode($stored['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
        foreach($items as $item)if(isset($changes[$item['id']]))return true;
        return false;
    }
}

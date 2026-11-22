<?php
declare(strict_types=1);
namespace Omnigo;

final class NewRecordBoundary
{
    public static function started(Database $db,int $company,string $provider,array $settings): int
    {
        $creation=$db->one('SELECT MIN(created) AS started FROM audit WHERE company_id=? AND action=?',
            [$company,CustomerOnboarding::CREATED_AUDIT])['started']??null;
        $connection=0;
        if(IntegrationImportSchema::available($db)){
            $connection=self::epoch($db->one('SELECT MIN(started_at) AS started FROM integration_import_runs
                WHERE company_id=? AND provider=?',[$company,$provider])['started']??null);
        }
        $start=max(self::epoch($creation),$connection?:self::epoch($settings['connectedAt']??$settings['notifyFrom']??null));
        if(!$start)throw new SyncFailure(422);
        return $start;
    }

    public static function epoch(mixed $value): int
    {
        return is_int($value)?$value:(is_string($value)?(strtotime($value)?:0):0);
    }
}

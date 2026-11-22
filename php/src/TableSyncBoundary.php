<?php
declare(strict_types=1);
namespace Omnigo;

final class TableSyncBoundary
{
    public static function first(Database $db,int $company,string $provider,array $config,array $state,int $through,string $table): int
    {
        $connectionFrom=self::date($config['startDate']??null) ?: self::date($config['salesStartDate']??null);
        $dates=array_filter([$connectionFrom ?: self::date($state['startDate']??null)]);
        $created=$db->one('SELECT after_value,created FROM audit WHERE company_id=? AND action=? ORDER BY id LIMIT 1',[$company,CustomerOnboarding::CREATED_AUDIT]);
        $setup=json_decode($created['after_value']??'{}',true,32,JSON_THROW_ON_ERROR);
        $storeFrom=self::date($setup['shipmentSince']??null);
        if(in_array($table,['shipments','shipmentInvoices','deliveries','cityProducts'],true)&&$storeFrom)$dates[]=$storeFrom;
        $from=$dates?max($dates):($storeFrom?:self::date($created['created']??$config['connectedAt']??null));
        if(!$from)throw new SyncFailure(422);
        if($provider==='trendyol'&&$table!=='inbox')$from=max($from,$through-89*86400);
        return $from;
    }

    private static function date(mixed $value): int
    {
        if(is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))
            return (new \DateTimeImmutable($value,new \DateTimeZone('Europe/Istanbul')))->getTimestamp();
        return NewRecordBoundary::epoch($value);
    }
}

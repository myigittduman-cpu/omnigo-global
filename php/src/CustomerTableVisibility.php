<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit customer grants for sensitive data, scoped to the table serving it. */
final class CustomerTableVisibility
{
    public const CANCELLATIONS = 'viewCancelledRecords';
    public const RETURNS = 'viewReturnedRecords';
    public const TURNOVER = 'viewAfterCancellationTurnover';
    public const CANCELLED_STATUS = 'viewCancelledStatus';
    public const STATUS_PAGES = ['deliveries','shipments','cityProducts','shipmentInvoices'];
    public const NONE = ['cancellations'=>false, 'returns'=>false, 'afterCancellationTurnover'=>false];
    public const ALL = ['cancellations'=>true, 'returns'=>true, 'afterCancellationTurnover'=>true];

    private const SALES = ['overview', 'overviewSales', 'overviewTop', 'analytics', 'reports'];
    private const OPERATIONAL = ['orders', 'inventory', 'deliveries', 'shipments',
        'shipmentInvoices', 'cityProducts', 'returns'];

    public static function actions(string $page): array
    {
        if (in_array($page, self::SALES, true)) return [self::CANCELLATIONS, self::RETURNS, self::TURNOVER];
        if (in_array($page, self::STATUS_PAGES, true)) return [self::CANCELLATIONS, self::RETURNS, self::CANCELLED_STATUS];
        return in_array($page, self::OPERATIONAL, true) ? [self::CANCELLATIONS, self::RETURNS] : [];
    }

    public static function canSeeCancelledStatus(array $access,string $page): bool
    {
        $grants=$access['grants'][$page]??[];
        return in_array($page,self::STATUS_PAGES,true) && ($access['pages'][$page]??null)==='shown'
            && ($grants['view']??false)===true && ($grants[self::CANCELLED_STATUS]??false)===true;
    }

    public static function withStatusDefaults(array $grants,array $explicit): array
    {
        foreach(self::STATUS_PAGES as $page){
            if(isset($grants[$page]) && !array_key_exists(self::CANCELLED_STATUS,(array)($explicit[$page]??[]))){
                $grants[$page]=(array)$grants[$page];
                $grants[$page][self::CANCELLED_STATUS]=($grants[$page]['view']??false)===true;
            }
        }
        return $grants;
    }

    public static function complete(string $page, array $grants, array $previous = []): array
    {
        foreach (self::actions($page) as $action) {
            if (!array_key_exists($action, $grants)) $grants[$action] = $previous[$action] ?? ($action===self::CANCELLED_STATUS && ($grants['view']??false)===true);
        }
        return $grants;
    }

    public static function preserveOmitted(array $value, array $previous): array
    {
        // Older editors did not receive this section or its actions.
        if (!array_key_exists('returns', $value) && isset($previous['returns'])) $value['returns']=$previous['returns'];
        if (!array_key_exists('cityProducts', $value) && isset($previous['cityProducts'])) $value['cityProducts']=$previous['cityProducts'];
        foreach ([...self::SALES,...self::OPERATIONAL] as $page) {
            if (isset($value[$page]) && (is_array($value[$page]) || $value[$page] instanceof \stdClass)) {
                $value[$page]=self::complete($page,(array)$value[$page],(array)($previous[$page]??[]));
            }
        }
        return $value;
    }

    public static function fromAccess(array $access, string $page): array
    {
        $grants=$access['grants'][$page]??[];
        if (($access['pages'][$page]??null)!=='shown' || ($grants['view']??false)!==true) return self::NONE;
        $supported=self::actions($page);
        $allowed=static fn(string $action):bool=>in_array($action,$supported,true)&&($grants[$action]??false)===true;
        return [
            'cancellations'=>$allowed(self::CANCELLATIONS),
            'returns'=>$allowed(self::RETURNS),
            'afterCancellationTurnover'=>$allowed(self::TURNOVER),
        ];
    }
}

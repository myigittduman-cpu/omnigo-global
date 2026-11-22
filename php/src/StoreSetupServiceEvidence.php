<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only proof of a complete, current-credential automatic service scan. */
final class StoreSetupServiceEvidence
{
    private const QUESTION_PROVIDERS=['n11','idefix','pazarama'];
    private const RETURN_PROVIDERS=['n11','trendyol'];

    public static function supported(string $provider,string $scope): bool
    {
        return match($scope){
            'inbox'=>in_array($provider,self::QUESTION_PROVIDERS,true),
            'returns'=>in_array($provider,self::RETURN_PROVIDERS,true),
            default=>false,
        };
    }

    public static function complete(Database $db,int $company,string $provider,string $scope,int $since,
        string $credentialRevision,?IntegrationVault $vault=null): bool
    {
        if($since<1||$credentialRevision===''||!self::supported($provider,$scope))return false;
        $credential=$db->one('SELECT updated FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
        $updated=self::timestamp($credential['updated']??null);
        if(!$updated)return false;
        $configuration=($vault??new IntegrationVault($db,dirname(__DIR__).'/storage/backup.key'))->get($company,$provider);
        if(empty($configuration['enabled'])||($configuration['revision']??null)!==$credentialRevision)return false;
        $since=max($since,$updated);

        if($scope==='inbox'){
            $state=self::record($db,$company,'sync_'.$provider.'_questions');
            return self::questions($provider,$state,$since,$credentialRevision);
        }
        // TrendyolClaimStore gates the complete snapshot by the current credential record's ID and version.
        $state=$provider==='trendyol'?(new TrendyolClaimStore($db))->read($company):self::record($db,$company,'sync_n11');
        return self::returns($provider,$state,$since,$credentialRevision);
    }

    private static function record(Database $db,int $company,string $kind): array
    {
        $row=$db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,$kind]);
        return json_decode($row['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
    }

    private static function questions(string $provider,array $state,int $since,string $revision): bool
    {
        if(($state['revision']??null)!==$revision||($state['phase']??null)!=='ready'||!empty($state['failure']))return false;
        $lastPage=self::timestamp($state['lastPageAt']??null);
        $completed=self::timestamp($state['lastSuccessAt']??null);
        if($lastPage<$since||$completed<$lastPage||$completed>time())return false;
        return match($provider){
            'n11'=>($state['historyComplete']??false)===true&&($state['page']??null)===0
                &&!isset($state['pending'])&&!isset($state['seen']),
            'idefix'=>($state['historyComplete']??false)===true&&($state['page']??null)===1
                &&($state['stream']??null)==='unanswered',
            'pazarama'=>($state['page']??null)===1,
            default=>false,
        };
    }

    private static function returns(string $provider,array $state,int $since,string $revision): bool
    {
        if(!empty($state['returnRequestError']))return false;
        $snapshot=$state['returnRequests']??[];
        $from=self::timestamp($snapshot['from']??null);
        $through=self::timestamp($snapshot['through']??null);
        $checked=self::timestamp($snapshot['checkedAt']??null);
        if(!is_array($snapshot['items']??null)||!array_is_list($snapshot['items'])||!$from
            ||$through<max($since,$from)||$checked<$through||$checked>time())return false;
        return match($provider){
            'n11'=>($state['snapshotRevision']??null)===$revision&&($state['startDate']??null)===$snapshot['from']
                &&($state['watermark']??null)===$snapshot['through'],
            'trendyol'=>is_int($state['throughMs']??null)&&intdiv($state['throughMs'],1000)===$through,
            default=>false,
        };
    }

    private static function timestamp(mixed $value): int
    {
        return is_string($value)&&preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d\d:\d\d)$/D',$value)
            ?(strtotime($value)?:0):0;
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

/** Append validated new payout dates without changing the last automatic snapshot. */
final class NewPayoutRecords
{
    public function __construct(private Database $db,private IntegrationVault $vault,private ?\Closure $readPage=null) {}

    public static function stateKey(string $provider): string
    {
        return 'freshpay_'.$provider;
    }

    public function step(int $company,string $provider,array $cursor,int $deadline,?callable $checkpoint=null): array
    {
        $page=$this->readPage?($this->readPage)($company,$provider,$cursor,$deadline)
            :(new UpcomingPayoutAdapter($this->db,$this->vault,provider:$provider))->readNewDatePage($company,$cursor,$deadline);
        $target=$cursor['target']??[];
        return TransactionRetry::run($this->db,function()use($company,$provider,$page,$checkpoint,$target):array{
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $connection=(new UpcomingPayoutStore($this->db))->connection($company,$provider);
            $cursor=$page['cursor'];
            if(!$connection||$connection!==$cursor['connection'])throw new SyncFailure(409);
            $result=$page['complete']?['cursor'=>[],'done'=>true,'added'=>($target['mode']??'')==='full'
                ?$this->publish($company,$provider,$connection,$cursor,$target['requestedAt'])
                :$this->append($company,$provider,$connection,$cursor)]
                :['cursor'=>$cursor,'done'=>false,'added'=>0,'delay'=>75];
            if($checkpoint!==null)$checkpoint($result);
            return $result;
        });
    }

    private function publish(int $company,string $provider,string $connection,array $cursor,int $requestedAt): int
    {
        $store=new FinanceStore($this->db);
        $key=self::stateKey($provider);
        $previous=$store->state($company,$key);
        $state=json_decode($previous['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
        $automatic=$store->state($company,UpcomingPayoutAdapter::stateKey($provider));
        $normal=json_decode($automatic['payload']??'{}',true,64,JSON_THROW_ON_ERROR)['published']??[];
        if(($normal['connection']??null)===$connection&&($normal['checkedAt']??0)>=$requestedAt)return 0;
        $old=$state['fullPublished']??[];
        $known=($old['connection']??null)===$connection?($old['days']??[]):[];
        if(($normal['connection']??null)===$connection)$known+=($normal['days']??[]);
        $days=array_filter($cursor['days'],static fn(int $cents):bool=>$cents>0);
        $state['fullPublished']=['days'=>$days,'connection'=>$connection,'checkedAt'=>time(),'requestedAt'=>$requestedAt];
        $store->writeState($company,$key,$state,$previous);
        return count(array_diff_key($days,$known));
    }

    private function append(int $company,string $provider,string $connection,array $cursor): int
    {
        $store=new FinanceStore($this->db);
        $key=self::stateKey($provider);
        $previous=$store->state($company,$key);
        $state=json_decode($previous['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
        $automatic=$store->state($company,UpcomingPayoutAdapter::stateKey($provider));
        $published=json_decode($automatic['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
        $today=$cursor['today'];
        $known=$this->knownDates($company,CustomerMarketplaces::PROVIDERS[$provider],$state+$published,$today);
        $rows=$state['newDates']??[];
        $rows=array_filter($rows,static fn(string $date):bool=>$date>=$today,ARRAY_FILTER_USE_KEY);
        $added=0;
        foreach($cursor['days'] as $day=>$amount){
            if(isset($known[$day])||$amount<=0)continue;
            $rows[$day]=['amount'=>$amount,'observedAt'=>time(),'connection'=>$connection];
            $added++;
        }
        if($added){
            $state['newDates']=$rows;
            $store->writeState($company,$key,$state,$previous);
        }
        return $added;
    }

    private function knownDates(int $company,string $channel,array $state,string $today): array
    {
        $keys=[...array_keys($state['published']['days']??[]),...array_keys($state['fullPublished']['days']??[]),...array_keys($state['newDates']??[]),...($state['knownDates']??[])];
        if(UpcomingPayoutReviewStore::available($this->db)){
            $keys=[...$keys,...array_column($this->db->all('SELECT payment_date FROM upcoming_payout_reviews
                WHERE company_id=? AND channel=? AND payment_date>=?',[$company,$channel,$today]),'payment_date')];
        }
        $calendar=$this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='payments'
            UNION ALL SELECT before_value AS payload FROM audit WHERE company_id=? AND action=? AND before_value IS NOT NULL",
            [$company,$company,'Hakediş silindi']);
        foreach($calendar as $record){
            $value=json_decode($record['payload'],true,64,JSON_THROW_ON_ERROR);
            if(($value['channel']??null)===$channel&&($value['expectedDate']??'')>=$today)$keys[]=$value['expectedDate'];
        }
        return array_fill_keys($keys,true);
    }

    public static function removedDates(Database $db,int $company,string $channel,string $today,?int $since=null): array
    {
        $removed=[];
        foreach($db->all('SELECT before_value,created FROM audit WHERE company_id=? AND action=? AND before_value IS NOT NULL',
            [$company,'Hakediş silindi']) as $record){
            if($since!==null&&NewRecordBoundary::epoch($record['created'])<$since)continue;
            $value=json_decode($record['before_value'],true,64,JSON_THROW_ON_ERROR);
            if(($value['channel']??null)===$channel&&($value['expectedDate']??'')>=$today)$removed[$value['expectedDate']]=true;
        }
        return $removed;
    }
}

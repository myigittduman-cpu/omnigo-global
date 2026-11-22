<?php
declare(strict_types=1);
namespace Omnigo;

/** Only source-dated claims beyond the last complete automatic coverage enter manual catch-up. */
final class NewReturnRecords
{
    private const PROVIDERS=['hepsiburada','n11','idefix','koctas','trendyol'];
    private const WINDOW=13*86400;

    public function __construct(
        private Database $db,
        private IntegrationVault $vault,
        DeliveryRepository $deliveries,
        private ?\Closure $reader=null,
    ) {}

    public static function supported(string $provider,array $settings): bool
    {
        return !empty($settings['enabled'])&&in_array($provider,self::PROVIDERS,true)
            &&($provider!=='hepsiburada'||(!empty($settings['salesEnabled'])&&!empty($settings['ordersEnabled'])));
    }

    public function step(int $company,string $provider,array $cursor,int $deadline,?callable $checkpoint=null): array
    {
        $settings=$this->vault->get($company,$provider);
        if(!$settings||!self::supported($provider,$settings))throw new SyncFailure(403);
        $connection=(new UpcomingPayoutStore($this->db))->connection($company,$provider);
        if(!$connection||($cursor['revision']??$connection)!==$connection)throw new SyncFailure(409);
        $limit=(int)($cursor['through']??time());
        $full=($cursor['target']['mode']??'')==='full';
        $from=$cursor['from']??($full?TableSyncBoundary::first($this->db,$company,$provider,$settings,[],$limit,'returns'):$this->afterAutomaticCoverage($company,$provider));
        if($from>$limit){
            return $this->append($company,$provider,$connection,$from,$limit,[],
                ['cursor'=>[],'done'=>true,'added'=>0],$checkpoint);
        }
        $end=min($limit,$from+self::WINDOW-1);
        $page=[...$cursor,'from'=>$from,'through'=>$end];
        [$items,$complete]=$this->reader
            ?($this->reader)($company,$provider,$settings,$page,$deadline)
            :(new NewReturnPage())->read($provider,$settings,$page,$deadline);
        $done=$complete&&$end===$limit;
        $next=$complete?['from'=>$end+1,'through'=>$limit,'revision'=>$connection]
            :[...$page,'through'=>$limit,'revision'=>$connection];
        return $this->append($company,$provider,$connection,$from,$end,$items,
            ['cursor'=>$next,'done'=>$done,'added'=>0,'delay'=>75],$checkpoint,$full?(int)$cursor['target']['requestedAt']:null);
    }

    private function afterAutomaticCoverage(int $company,string $provider): int
    {
        $snapshot=self::automatic($this->db,$company,$provider)['returnRequests']??[];
        $from=NewRecordBoundary::epoch($snapshot['from']??null);
        $through=NewRecordBoundary::epoch($snapshot['through']??null);
        if(!$from||$through<$from||!is_array($snapshot['items']??null)||!NewRecordBoundary::epoch($snapshot['checkedAt']??null))
            throw new SyncFailure(503,75);
        return $through+1;
    }

    private static function automatic(Database $db,int $company,string $provider): array
    {
        if($provider==='trendyol')return (new TrendyolClaimStore($db))->read($company);
        $row=$db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);
        $state=json_decode($row['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
        return $provider==='hepsiburada'?($state['financial']??[]):$state;
    }

    private function append(int $company,string $provider,string $connection,int $from,int $through,array $items,
        array $result,?callable $checkpoint,?int $fullSince=null): array
    {
        return TransactionRetry::run($this->db,function()use($company,$provider,$connection,$from,$through,$items,$result,$checkpoint,$fullSince){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if((new UpcomingPayoutStore($this->db))->connection($company,$provider)!==$connection)throw new SyncFailure(409);
            if(!$items){
                if($checkpoint!==null)$checkpoint($result);
                return $result;
            }
            $state=self::automatic($this->db,$company,$provider);
            $snapshot=$state['returnRequests']??[];
            $known=array_fill_keys(array_column($snapshot['items']??[],'id'),true);
            $known+=$this->historicClaims($company,$provider);
            if($provider==='hepsiburada')$known+=$state['refunds']??[];
            // Ordinary sync may have finished another complete window while the GET was in flight.
            if($fullSince===null||NewRecordBoundary::epoch($snapshot['checkedAt']??null)>=$fullSince)
                $from=max($from,NewRecordBoundary::epoch($snapshot['through']??null)+1);
            $finance=new FinanceStore($this->db);
            $key='new_ret_'.$provider;
            $before=$finance->state($company,$key);
            $manual=json_decode($before['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
            $entries=$manual['entries']??[];
            $seen=$manual['seen']??[];
            $now=time();
            $checked=NewRecordBoundary::epoch($snapshot['checkedAt']??null);
            $covered=NewRecordBoundary::epoch($snapshot['through']??null);
            foreach($entries as $id=>$entry){
                $seen[self::identity((string)$id)]=true;
                if(self::covered($entry,$checked,$covered))unset($entries[$id]);
            }
            $added=0;
            $changed=false;
            foreach($items as $item){
                $id=(string)($item['id']??'');
                $created=NewRecordBoundary::epoch($item['createdAt']??null);
                if($id===''||empty($item['orderNumber'])||empty($item['status']))throw new SyncFailure(422);
                $alreadyKnown=isset($known[$id])||isset($seen[self::identity($id)]);
                if(!$created||$created<$from||$created>$through||($alreadyKnown&&$fullSince===null))continue;
                $seen[self::identity($id)]=true;
                $entries[$id]=['connection'=>$connection,'at'=>$now,'claim'=>$item]+($fullSince!==null?['fullSince'=>$fullSince]:[]);
                $changed=true;
                if(!$alreadyKnown)$added++;
            }
            if(count($seen)>20000)throw new SyncFailure(422);
            if($changed)$finance->writeState($company,$key,['entries'=>$entries,'seen'=>$seen],$before);
            $result['added']=$added;
            if($checkpoint!==null)$checkpoint($result);
            return $result;
        });
    }

    private function historicClaims(int $company,string $provider): array
    {
        if(!IntegrationImportSchema::tableAvailable($this->db,'order_notifications','id'))return [];
        $known=$this->db->one("SELECT payload FROM order_notifications WHERE company_id=? AND provider=? AND order_key=? AND event_type='checkpoint'",
            [$company,$provider,hash('sha256','return-notification-cursor')]);
        return json_decode($known['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
    }

    private static function identity(string $id): string {return hash('sha256','claim:'.$id);}

    public static function merge(Database $db,int $company,string $provider,array $automatic,?string $checkedAt,?string $through=null): array
    {
        if(!in_array($provider,self::PROVIDERS,true)||!PazaramaCommissionStore::available($db))return $automatic;
        $row=(new FinanceStore($db))->state($company,'new_ret_'.$provider);
        if(!$row)return $automatic;
        $manual=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        $connection=(new UpcomingPayoutStore($db))->connection($company,$provider);
        if(!$connection)return $automatic;
        $keys=[];
        foreach($automatic as $index=>$row)if(isset($row['id']))$keys[$row['id']]=$index;
        $checked=NewRecordBoundary::epoch($checkedAt);
        $covered=NewRecordBoundary::epoch($through??$checkedAt);
        foreach($manual['entries']??[] as $id=>$entry){
            $item=$entry['claim'];
            if($entry['connection']!==$connection||self::covered($entry,$checked,$covered))continue;
            if(!NewRecordBoundary::epoch($item['createdAt']??null)||($item['type']??null)==='cancellation')continue;
            if(isset($keys[$id])){
                if(($entry['fullSince']??0)>$checked)$automatic[$keys[$id]]=$item;
            }else $automatic[]=$item;
        }
        return $automatic;
    }

    private static function covered(array $entry,int $checked,int $covered): bool
    {
        $created=NewRecordBoundary::epoch($entry['claim']['createdAt']??null);
        return $created>0&&$checked>=$entry['at']&&$covered>=$created;
    }
}

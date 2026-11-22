<?php
declare(strict_types=1);
namespace Omnigo;

/** Manual catalogue catch-up with an independent cursor and append-only listing writes. */
final class NewInventoryRecords
{
    private const REQUEST_INTERVAL=65;

    public function __construct(
        private Database $db,
        private IntegrationVault $vault,
        private ?\Closure $reader=null,
    ) {}

    public function step(int $company,string $provider,array $cursor,int $deadline,?callable $checkpoint=null): array
    {
        $label=InventoryCatalogPage::LABELS[$provider]??null;
        if($label===null)throw new SyncFailure(422);
        $scope=$this->scope($company,$provider,$label);
        if(($cursor['connection']??$scope)!==$scope)throw new SyncFailure(409);
        $cursor['connection']=$scope;
        $this->checkAvailability($company,$provider);

        $page=$cursor['page']??0;
        $partition=$cursor['partition']??0;
        $partitions=match($provider){'pttavm'=>4,'trendyol'=>2,default=>1};
        if(!is_int($page)||$page<0||$page>10000||!is_int($partition)||$partition<0||$partition>=$partitions)
            throw new SyncFailure(422);
        if(time()>=$deadline)throw new SyncFailure(503);
        $config=$this->vault->get($company,$provider);
        if(empty($config['enabled']))throw new SyncFailure(403);
        $config['_inventoryPartition']=$partition;
        $config['_inventoryCursor']=$cursor['token']??null;
        $raw=$this->reader?($this->reader)($provider,$config,$page,$deadline):(new InventoryCatalogClient())->page($provider,$config,$page,$deadline);
        $result=InventoryCatalogPage::normalize($provider,$raw,$page,$partition);
        [$cursor,$done]=$this->nextCursor($cursor,$result,$partitions,$page,$partition);

        return TransactionRetry::run($this->db,function()use($company,$provider,$label,$scope,$result,$deadline,$cursor,$done,$checkpoint):array{
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))throw new SyncFailure(404);
            if($this->scope($company,$provider,$label)!==$scope)throw new SyncFailure(409);
            if(time()>=$deadline)throw new SyncFailure(503);
            $catalog=new InventoryCatalog($this->db);
            $known=$this->knownListings($company,$label,$result['items']);
            $added=0;
            foreach($result['items'] as $listing){
                $key=hash('sha256',$listing['id']);
                if(($cursor['target']['mode']??'')==='full'){
                    if(time()>=$deadline)throw new SyncFailure(503);
                    $product=$catalog->ingestReplay($company,$label,$listing);
                    if($product===null)continue;
                    $linked=$this->db->one("SELECT product_key FROM inventory_links WHERE company_id=? AND provider=? AND source_product=? AND state='active'",[$company,$label,$product]);
                    $added+=TableCatalogReplay::restore($this->db,$company,$cursor['target']['table'],$linked['product_key']??$product,$cursor['target']['requestedAt']);
                    if(!isset($known[$key]))$added++;
                    $known[$key]=true;
                    continue;
                }
                if(isset($known[$key]))continue;
                if(time()>=$deadline)throw new SyncFailure(503);
                if($catalog->ingestNew($company,$label,$listing)!==null)$added++;
                $known[$key]=true;
            }
            $checkpointResult=compact('cursor','done','added')+['delay'=>self::REQUEST_INTERVAL];
            if($checkpoint!==null)$checkpoint($checkpointResult);
            return $checkpointResult;
        });
    }

    private function knownListings(int $company,string $label,array $listings): array
    {
        $keys=array_unique(array_map(static fn(array $listing):string=>hash('sha256',$listing['id']),$listings));
        $known=[];
        foreach(array_chunk($keys,500) as $chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            foreach($this->db->all("SELECT listing_key FROM inventory_listings WHERE company_id=? AND provider=? AND listing_key IN ($marks)",
                [$company,$label,...$chunk]) as $row)$known[$row['listing_key']]=true;
        }
        return $known;
    }

    private function scope(int $company,string $provider,string $label): array
    {
        $stock=new InventorySettings($this->db);
        if(!$stock->marketplaceEnabled($company))throw new SyncFailure(403);
        $credential=$this->db->one('SELECT id,version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);
        if(!$credential)throw new SyncFailure(403);
        foreach($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='channels'",[$company]) as $row){
            $channel=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
            if(($channel['name']??null)!==$label||($channel['status']??null)!=='active'||!CustomerMarketplaces::available($channel))continue;
            if(array_key_exists('selected',$channel)&&!$channel['selected'])continue;
            return ['id'=>(int)$credential['id'],'version'=>(int)$credential['version'],'stock'=>$stock->preferences($company)];
        }
        throw new SyncFailure(403);
    }

    private function checkAvailability(int $company,string $provider): void
    {
        $automatic=$this->db->one('SELECT j.next_run,j.attempts,p.lease_until,p.heartbeat
            FROM inventory_catalog_jobs j JOIN inventory_catalog_progress p ON p.company_id=j.company_id AND p.provider=j.provider
            WHERE j.company_id=? AND j.provider=?',[$company,$provider]);
        if(!$automatic)throw new SyncFailure(503,self::REQUEST_INTERVAL);
        $now=time();
        $next=max((int)$automatic['lease_until'],(int)$automatic['heartbeat']+self::REQUEST_INTERVAL,
            (int)$automatic['attempts']>0?(int)$automatic['next_run']:0);
        if($next>$now)throw new ProviderRequestDeferred($next);
    }

    private function nextCursor(array $cursor,array $result,int $partitions,int $page,int $partition): array
    {
        $ids=$result['sourceIds']??array_column($result['items'],'id');
        if($ids){
            sort($ids,SORT_STRING);
            $fingerprint=hash('sha256',json_encode($ids,JSON_THROW_ON_ERROR));
            if(isset($cursor['fingerprints'][$fingerprint]))throw new SyncFailure(409,self::REQUEST_INTERVAL);
            $cursor['fingerprints'][$fingerprint]=true;
        }elseif($result['next'])throw new SyncFailure(409,self::REQUEST_INTERVAL);

        if(isset($result['sourceTotal'])){
            if(($cursor['sourceTotal']??$result['sourceTotal'])!==$result['sourceTotal'])throw new SyncFailure(409,self::REQUEST_INTERVAL);
            $cursor['sourceTotal']=$result['sourceTotal'];
        }
        if($result['next']){
            if($page===10000)throw new SyncFailure(422);
            $cursor['page']=$page+1;
            $cursor['partition']=$partition;
            $cursor['token']=$result['cursor']??null;
            return [$cursor,false];
        }

        unset($cursor['fingerprints'],$cursor['sourceTotal'],$cursor['token']);
        $cursor['page']=0;
        $cursor['partition']=$partition+1;
        return [$cursor,$cursor['partition']>=$partitions];
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

/** The selected store's existing queue and snapshot metadata. */
final class MarketplaceServiceHealth
{
    private const QUESTIONS=[
        'hepsiburada'=>HepsiburadaQuestionClient::JOB,'n11'=>'n11 Sorular','pazarama'=>'Pazarama Sorular',
        'idefix'=>'idefix Sorular','trendyol'=>TrendyolQuestionClient::JOB,'koctas'=>'Koçtaş Sorular',
    ];
    public function __construct(private Database $db) {}

    public function channel(int $company,string $provider,array $sync,array $jobs,bool $configured,bool $active):array
    {
        $sources=[];
        if(isset(self::QUESTIONS[$provider])) {
            $key=match($provider){'hepsiburada'=>HepsiburadaQuestionClient::STATE_KEY,'trendyol'=>TrendyolQuestionAdapter::STATE_KEY,default=>null};
            $state=$key===null?$this->state($company,'sync_'.$provider.'_questions'):$this->financeState($company,$key);
            $job=$jobs[self::QUESTIONS[$provider]]??null;
            $sources['questions']=$this->status($job,$state['lastSuccessAt']??null,$configured,$active,1800,
                ($state['phase']??null)==='syncing'||isset($state['scan']));
        }
        if(isset(UpcomingPayoutAdapter::JOBS[$provider])||isset(UpcomingPayoutSource::PANEL_CHANNELS[$provider])){
            $sources['payouts']=$this->payout($company,$provider,$jobs,$configured,$active);
        }elseif($provider==='trendyol'&&$configured&&$active){
            $manual=CustomerSetupSelection::hasManual($this->db,$company,'payoutEstimates');
            $sources['payouts']=[...$this->manual(true),'status'=>$manual?'manual':'probe','coverage'=>'paid_orders_only'];
        }
        if(isset(ReturnRequestList::PROVIDERS[$provider])){
            $sources['requests']=$this->requests($company,$provider,$sync,$jobs,$configured,$active);
        }
        if($provider==='hepsiburada'&&$configured&&(new InboxRepository($this->db))->ready()){
            $sources['questions']['verificationCount']=(int)$this->db->one("SELECT COUNT(*) AS n FROM inbox_jobs j JOIN inbox_threads t ON t.id=j.thread_id AND t.company_id=j.company_id
                WHERE j.company_id=? AND t.provider='hepsiburada' AND j.kind='reply' AND j.state IN ('unknown','verifying')",[$company])['n'];
        }
        return $sources;
    }

    private function requests(int $company,string $provider,array $sync,array $jobs,bool $configured,bool $active):array
    {
        if($provider==='pttavm'){
            $last=$sync['lastSuccessAt']??null;
            $status=$this->status($jobs['PTT AVM']??null,$last,$configured,$active,
                5*SyncFreshness::TARGET_SECONDS,isset($sync['scan']));
            if($status['status']==='ready')$status['status']='probe';
            return [...$status,'coverage'=>'order_status_returns'];
        }
        if($provider==='trendyol'){
            $state=$configured?(new TrendyolClaimStore($this->db))->read($company):[];
            return $this->status($jobs[TrendyolClaimAdapter::JOB]??null,$state['returnRequests']['checkedAt']??null,
                $configured,$active,1800,isset($state['scan']));
        }
        $state=$provider==='hepsiburada'?($sync['financial']??[]):$sync;
        $last=$state['returnRequests']['checkedAt']??null;
        $status=!$active?'inactive':(!$configured?'not_configured':(!empty($state['returnRequestError'])?'retry':(!$last?'probe':
            (SyncFreshness::age($last,time())>3600?'stale':'ready'))));
        return ['status'=>$status,'lastSuccessAt'=>$last,'nextRunAt'=>null,'attempts'=>0,'count'=>null];
    }

    public function payout(int $company,string $provider,array $jobs,bool $configured,bool $active): array
    {
        if(!isset(UpcomingPayoutAdapter::JOBS[$provider]))return $this->manual($active);
        $state=$configured?$this->financeState($company,UpcomingPayoutAdapter::stateKey($provider)):[];
        $published=$state['published']??[];
        $current=(new UpcomingPayoutStore($this->db))->connection($company,$provider);
        $valid=$current!==null&&($published['connection']??null)===$current&&is_int($published['checkedAt']??null);
        $last=$valid?gmdate(DATE_ATOM,$published['checkedAt']):null;
        $out=$this->status($jobs[UpcomingPayoutAdapter::JOBS[$provider]]??null,$last,$configured,$active,
            2*UpcomingPayoutAdapter::REFRESH_INTERVAL,isset($state['scan']));
        $out['count']=$valid?count($published['days']??[]):null;
        return $out;
    }

    public function payoutWarnings(int $company,array $channels): array
    {
        $jobs=array_column($this->db->all('SELECT provider,state,attempts,next_run FROM jobs WHERE company_id=?',[$company]),null,'provider');
        $store=new UpcomingPayoutStore($this->db);
        $warnings=[];
        foreach(UpcomingPayoutAdapter::JOBS as $provider=>$unused){
            $channel=CustomerMarketplaces::PROVIDERS[$provider];
            if(!in_array($channel,$channels,true)||!$store->connection($company,$provider))continue;
            $status=$this->payout($company,$provider,$jobs,true,true)['status'];
            if(in_array($status,['error','retry','stale','missing_queue'],true))$warnings[]=['channel'=>$channel,'status'=>$status];
        }
        return $warnings;
    }

    private function state(int $company,string $kind): array
    {
        $row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,$kind]);
        return $row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
    }

    private function financeState(int $company,string $key): array
    {
        if(!PazaramaCommissionStore::available($this->db))return [];
        $row=(new FinanceStore($this->db))->state($company,$key);
        return $row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
    }

    private function manual(bool $active): array
    {
        return ['status'=>$active?'manual':'inactive','lastSuccessAt'=>null,'nextRunAt'=>null,'attempts'=>0,'count'=>null];
    }

    private function status(?array $job,?string $last,bool $configured,bool $active,int $freshFor,bool $scanning): array
    {
        $attempts=(int)($job['attempts']??0);
        $next=isset($job['next_run'])?(int)$job['next_run']:null;
        $state=$job['state']??null;
        $status=match (true) {
            !$active=>'inactive',!$configured=>'not_configured',!$job=>'missing_queue',
            in_array($state,['blocked','failed'],true)=>'error',
            ($state==='running'||$scanning)&&$attempts===0&&$last!==null&&SyncFreshness::age($last,time())<=$freshFor=>'ready',
            $state==='running'=>'running',$attempts>0=>'retry',
            $next!==null&&$next<time()-$freshFor=>'stale',$scanning=>'running',
            $last===null=>'waiting',SyncFreshness::age($last,time())>$freshFor=>'stale',default=>'ready',
        };
        return ['status'=>$status,'lastSuccessAt'=>$last,'nextRunAt'=>$state==='pending'&&$next!==null?gmdate(DATE_ATOM,$next):null,'attempts'=>$attempts,'count'=>null];
    }
}

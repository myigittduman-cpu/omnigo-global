<?php
declare(strict_types=1);
namespace Omnigo;

/** Local queue and completed sync evidence; never calls marketplace APIs. */
final class IntegrationHealthSnapshot
{
    public const PROVIDERS=['koctas'=>'Koçtaş','n11'=>'n11','pazarama'=>'Pazarama','hepsiburada'=>'Hepsiburada','idefix'=>'idefix','pttavm'=>'PTT AVM','trendyol'=>'Trendyol','teknosa'=>'Teknosa'];
    public function __construct(private Database $db){}
    public function company(int $company,?int $now=null): array
    {
        $now??=time();$result=[];
        $channels=(new Repository($this->db))->records($company,'channels',['role'=>'admin']);
        $jobs=array_filter($this->db->all('SELECT provider,state,attempts,next_run,lease_until FROM jobs WHERE company_id=?',[$company]),fn($job)=>!PaymentPolicy::automaticJob($job['provider']));
        foreach($channels as $channel){
            if($channel['name']==='Trendyol'&&empty($channel['selected']))continue;
            $provider=array_search($channel['name'],self::PROVIDERS,true);if($provider===false)continue;
            $item=['service'=>$channel['name'],'status'=>'not_configured','message'=>'API bağlantısı kurulmadı.','lastSuccessAt'=>null,'jobs'=>[],'requiredJobs'=>[]];
            if($channel['status']!=='active'){$result[$provider]=[...$item,'status'=>'inactive','message'=>'Pazaryeri pasif.'];continue;}
            if(!$this->db->one('SELECT id FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider])){$result[$provider]=$item;continue;}
            $selected=array_filter($jobs,fn($job)=>$job['provider']===$channel['name']||str_starts_with($job['provider'],$channel['name'].' ')
                ||($provider===HepsiburadaQuestionClient::PROVIDER&&$job['provider']===HepsiburadaQuestionClient::JOB));$reasons=[];
            $isolated=(new OrderImportQueue($this->db))->count($company,$provider);
            if($isolated){$reasons[]=$isolated.' siparişin kaynak verisi çelişkili; diğer siparişler aktarılıyor, bu kayıtlar otomatik yeniden kontrol ediliyor.';$item['requiredJobs'][]=$channel['name'];}
            if(!$selected){$reasons[]='Zamanlanmış aktarım görevi bulunamadı.';$item['requiredJobs'][]=$channel['name'];}
            foreach($selected as $job){
                $sync=$this->sync($company,$provider,$job['provider']);
                $last=$sync['lastSuccessAt']??$sync['watermark']??(in_array($sync['phase']??'',['verified','review_ready'],true)?($sync['checkedAt']??null):null);
                $item['jobs'][$job['provider']]=['successAt'=>$last,'complete'=>!$isolated&&empty($sync['financialSnapshotHeld'])&&!isset($sync['scan'])&&!isset($sync['scanEnd'])&&!in_array($sync['phase']??'',['error','retry','syncing','reading'],true)&&empty($sync['failure']),'idle'=>$job['state']==='pending'&&(int)$job['attempts']===0];
                if($job['provider']===$channel['name'])$item['lastSuccessAt']=$last;
                $reason=match(true){
                    $job['state']==='blocked'=>'API erişimi doğrulanamadı. Bağlantı bilgileri veya yetkiler kontrol edilmeli.',
                    $job['state']==='failed'=>'Aktarım yeniden denemelere rağmen tamamlanamadı.',
                    $job['state']==='pending'&&(int)$job['attempts']>0=>'Aktarım denemesi başarısız oldu; kuyruk yeniden deneyecek.',
                    $job['state']==='pending'&&(int)$job['next_run']<$now-($job['provider']===$channel['name']&&SyncFreshness::fast($provider)?SyncFreshness::ALERT_SECONDS:1800)=>'Zamanlanmış aktarım gecikti.',
                    $job['state']==='running'&&(int)$job['lease_until']<$now-300=>'Aktarım görevi yanıt vermiyor.',
                    !empty($sync['failure'])||in_array($sync['phase']??'',['error','retry'],true)=>'Son aktarım tamamlanamadı; başarılı kontrol bekleniyor.',
                    !empty($sync['financialSnapshotHeld'])=>'Çelişkili kaynak nedeniyle son doğrulanmış satış özeti korunuyor; yeniden kontrol sürüyor.',
                    default=>null
                };
                if($reason){$reasons[]=$job['provider'].': '.$reason;$item['requiredJobs'][]=$job['provider'];}
            }
            if(!isset($item['jobs'][$channel['name']])){$reasons[]='Sipariş aktarım görevi bulunamadı.';$item['requiredJobs'][]=$channel['name'];}
            $age=SyncFreshness::age($item['lastSuccessAt'],$now);$fast=SyncFreshness::fast($provider);
            if($age!==null&&$age>($fast?SyncFreshness::ALERT_SECONDS:7200)){$reasons[]=$fast?'Beş dakikadır başarılı sipariş / satış aktarımı doğrulanamadı.':'İki saattir başarılı veri aktarımı doğrulanamadı.';$item['requiredJobs'][]=$channel['name'];}
            $warning=$fast&&$age!==null&&$age>SyncFreshness::TARGET_SECONDS;
            $item['requiredJobs']=array_values(array_unique($item['requiredJobs']));
            $result[$provider]=[...$item,'status'=>$reasons?'error':($warning?'warning':($item['lastSuccessAt']?'healthy':'checking')),'message'=>$reasons?implode(' ',array_unique($reasons)):($warning?'Son başarılı aktarım iki dakikadan eski; güncel veri bekleniyor.':($item['lastSuccessAt']?'Bağlantı sorunu yok.':'İlk başarılı kontrol bekleniyor.'))];
        }
        return $result;
    }
    private function sync(int $company,string $provider,string $job): array
    {
        if($job===HepsiburadaQuestionClient::JOB)$row=(new FinanceStore($this->db))->state($company,HepsiburadaQuestionClient::STATE_KEY);
        elseif($job===TrendyolQuestionClient::JOB)$row=(new FinanceStore($this->db))->state($company,TrendyolQuestionAdapter::STATE_KEY);
        elseif(str_ends_with($job,' Hakediş'))$row=$this->db->one('SELECT payload FROM finance_sync WHERE company_id=? AND provider=?',[$company,$provider]);
        else $row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider.(str_ends_with($job,' Sorular')?'_questions':'')]);
        $state=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
        if($job==='Hepsiburada Satış'){$state=$state['financial']??[];if(!empty($state['error']))$state['failure']=true;}
        return $state;
    }
    public static function successAfterIncident(string $job, ?string $lastSuccess, array $incident): bool
    {
        $last = strtotime($lastSuccess ?? '') ?: 0;
        $previous = strtotime($incident['waitingFor'][$job] ?? '') ?: 0;
        $detected = strtotime($incident['detectedAt'] ?? '') ?: 0;
        return $last > $previous && $last >= $detected;
    }

    public static function recovered(array $snapshot,array $incident): bool
    {
        if($snapshot['status']!=='healthy'||empty($incident['waitingFor']))return false;
        foreach(array_keys($incident['waitingFor']) as $job){
            if(PaymentPolicy::automaticJob($job))continue;
            $current=$snapshot['jobs'][$job]??[];
            if(empty($current['idle'])||empty($current['complete'])||!self::successAfterIncident($job,$current['successAt']??null,$incident))return false;
        }
        return true;
    }
    public function badges(int $company): array
    {
        $row=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='integration_health'",[$company]);$incidents=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];$result=[];
        foreach($this->company($company) as $provider=>$item){
            $waiting=array_filter(array_keys($incidents[$provider]['waitingFor']??[]),fn($job)=>!PaymentPolicy::automaticJob($job));
            if($waiting&&in_array($item['status'],['healthy','checking','warning'],true)&&!empty($incidents[$provider]['open']))$item=[...$item,'status'=>self::recovered($item,$incidents[$provider])?'warning':'error','message'=>'Bağlantı sorunu sonrası bekleyen aktarım ve tablo kontrollerinin tamamlanması bekleniyor.'];
            $result[$item['service']]=array_intersect_key($item,array_flip(['status','message','lastSuccessAt']));
        }
        return $result;
    }
}

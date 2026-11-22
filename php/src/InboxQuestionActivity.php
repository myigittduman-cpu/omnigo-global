<?php
declare(strict_types=1);
namespace Omnigo;

/** Read recent question activity for the selected store and marketplace. */
final class InboxQuestionActivity
{
    private const SCHEDULES = [
        'hepsiburada'=>HepsiburadaQuestionClient::JOB,
        'n11'=>'n11 Sorular',
        'pazarama'=>'Pazarama Sorular',
        'idefix'=>'idefix Sorular',
        'trendyol'=>TrendyolQuestionClient::JOB,
    ];

    public function __construct(private Database $db) {}

    public function recent(?int $company,?string $provider): array
    {
        // At present only the Koçtaş manual scan is created without a conversation.
        $source="COALESCE(t.provider,CASE WHEN j.kind='scan' AND j.thread_id IS NULL THEN 'koctas' END)";
        $where=[];
        $parameters=[];
        if($company!==null){$where[]='j.company_id=?';$parameters[]=$company;}
        if($provider!==null){$where[]="$source=?";$parameters[]=$provider;}
        $filters=$where?' WHERE '.implode(' AND ',$where):'';
        $evidence=InboxHepsiburadaReplyVerification::sourceAnsweredSql('j');
        $jobs=$this->db->all("SELECT j.id,j.company_id,j.thread_id,j.kind,j.state,j.error,j.created,c.name AS company,$source AS source,$evidence AS source_answered
            FROM inbox_jobs j JOIN companies c ON c.id=j.company_id
            LEFT JOIN inbox_threads t ON t.id=j.thread_id AND t.company_id=j.company_id
            $filters ORDER BY j.created DESC,j.id DESC LIMIT 15",$parameters);
        foreach($jobs as &$job){
            $job['provider']=InboxQuestionFilters::PROVIDERS[$job['source']]??'Pazaryeri';
            if($job['kind']==='reply')$job=InboxHepsiburadaReplyVerification::presentation($job,$job['source']??'',(bool)$job['source_answered']);
            unset($job['source'],$job['source_answered']);
        }
        unset($job);
        if($company!==null&&$provider!==null&&isset(self::SCHEDULES[$provider])){
            $jobs=[...$jobs,...$this->scheduled($company,$provider)];
        }
        usort($jobs,static fn(array $left,array $right):int=>strcmp((string)$right['created'],(string)$left['created'])?:strcmp((string)$right['id'],(string)$left['id']));
        return array_slice($jobs,0,15);
    }

    private function scheduled(int $company,string $provider): array
    {
        $job=$this->db->one('SELECT j.id,j.state,j.attempts,j.error,c.name AS company FROM jobs j JOIN companies c ON c.id=j.company_id
            WHERE j.company_id=? AND j.provider=?',[$company,self::SCHEDULES[$provider]]);
        if(!$job)return [];
        $source=$this->snapshot($company,$provider);
        $event=['id'=>'scheduled-'.(int)$job['id'],'company_id'=>$company,'thread_id'=>null,'kind'=>'scheduled',
            'provider'=>InboxQuestionFilters::PROVIDERS[$provider],'company'=>$job['company'],'error'=>null];
        $lastSuccess=$this->stamp($source['lastSuccessAt']??null);
        $activity=$lastSuccess?[[...$event,'state'=>'done','created'=>$lastSuccess]]:[];
        $state=match(true){
            $job['state']==='blocked'=>'blocked',
            $job['state']==='failed'||!empty($source['failure'])||(int)$job['attempts']>0&&$job['state']!=='running'=>'failed',
            $job['state']==='running'||($source['phase']??null)==='syncing'=>'running',
            default=>'pending',
        };
        if($state==='pending'&&$lastSuccess!==null)return $activity;
        $error=match($state){
            'blocked'=>'Pazaryeri yetkisi doğrulanamadı.',
            'failed'=>'Soru aktarımı tamamlanamadı. Bağlantı durumunu kontrol edin.',
            default=>null,
        };
        return [...$activity,[...$event,'id'=>$event['id'].'-current','state'=>$state,'created'=>null,'error'=>$error]];
    }

    private function snapshot(int $company,string $provider): array
    {
        $finance=match($provider){
            'hepsiburada'=>HepsiburadaQuestionClient::STATE_KEY,
            'trendyol'=>TrendyolQuestionAdapter::STATE_KEY,
            default=>null,
        };
        if($finance===null){
            $row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider.'_questions']);
            return $row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
        }
        try{$row=(new FinanceStore($this->db))->state($company,$finance);}
        catch(\PDOException $error){
            if($error->getCode()!=='42S02'&&!str_contains($error->getMessage(),'no such table: finance_sync'))throw $error;
            return [];
        }
        return $row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
    }

    private function stamp(mixed $value): ?string
    {
        if(!is_string($value)||($time=strtotime($value))===false)return null;
        return gmdate('c',$time);
    }
}

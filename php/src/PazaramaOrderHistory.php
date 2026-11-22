<?php
declare(strict_types=1);
namespace Omnigo;

/** A supplemental queue. Its state is independent of the live Pazarama snapshot and job. */
final class PazaramaOrderHistory
{
    private PazaramaHistoryJobStore $jobs;

    public function __construct(private Database $db,private PazaramaHistoryImporter $importer)
    {
        $this->jobs=new PazaramaHistoryJobStore($db);
    }

    public function available(): bool
    {
        return $this->jobs->available();
    }

    public function status(int $company,?string $liveFrom): ?array
    {
        $bounds=$this->bounds($company,$liveFrom);
        if(!$bounds)return null;
        $expected=$this->importer->expected($company,$bounds['from'],$bounds['untilExclusive']);
        if(!$expected)return null;
        $job=$this->available()?$this->jobs->get($company):null;
        return $bounds+$this->importer->coverage($company,$expected)+['job'=>$job?[
            'state'=>$job['state'],'from'=>$job['from_date'],'cursorDay'=>$job['cursor_day'],
            'nextRun'=>$job['state']==='queued'?gmdate('c',max(time(),(int)$job['next_run'])):null,
            'errorCode'=>$job['error_code']!==null?(int)$job['error_code']:null,'requestedAt'=>$job['requested_at'],'updatedAt'=>$job['updated_at']]:null];
    }

    private function bounds(int $company,?string $liveFrom): ?array
    {
        if(!$liveFrom)return null;
        $bounds=$this->importer->bounds($company);
        if(!$bounds)return null;
        $bounds['untilExclusive']=min($bounds['untilExclusive'],SalesCalendar::day($liveFrom));
        return $bounds['from']<$bounds['untilExclusive']?$bounds:null;
    }

    public function request(int $company,array $input,int $actor): void
    {
        $from=Validation::date($input['from']??null);
        if(!is_int($input['version']??null)||$input['version']<1||array_diff(array_keys($input),['action','from','version']))
            Http::fail(400,'Bağlantı sürümü ve geçmiş sipariş başlangıcını kontrol edin.');
        if(!$this->available())Http::fail(503,'Arşiv aktarım kuyruğu şu anda kullanılamıyor.');
        TransactionRetry::run($this->db,function()use($company,$input,$from,$actor){
            $job=$this->jobs->get($company,true);
            $connection=$this->db->one("SELECT version FROM records WHERE company_id=? AND kind='integration_pazarama'",[$company]);
            if(!$connection||(int)$connection['version']!==$input['version'])Http::fail(409,'Pazarama bağlantısı değişti. Durumu yenileyin.');
            $row=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_pazarama'",[$company]);
            $sync=json_decode($row['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
            $bounds=$this->bounds($company,$sync['startDate']??null);
            if(!$bounds||$from<$bounds['from']||$from>=$bounds['untilExclusive'])Http::fail(400,'Doğrulanmış arşiv döneminden bir tarih girin.');
            if($job&&in_array($job['state'],['queued','running'],true)){
                if($job['from_date']===$from&&(int)$job['connection_version']===$input['version'])return;
                Http::fail(409,'Pazarama arşiv aktarımı zaten sürüyor.');
            }
            $expected=$this->importer->expected($company,$from,$bounds['untilExclusive']);
            if(!$expected||$this->importer->coverage($company,$expected)['missing']===0)return;
            $time=gmdate('c');
            $this->jobs->save([
                'company_id'=>$company,'connection_version'=>$input['version'],'from_date'=>$from,
                'until_date'=>$bounds['untilExclusive'],'cursor_day'=>$from,'page_no'=>1,'state'=>'queued',
                'attempts'=>0,'token'=>null,'next_run'=>0,'page_hash'=>null,'error_code'=>null,
                'requested_at'=>$time,'updated_at'=>$time,
            ]);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,after_value,created) VALUES(?,?,?,?,?)',
                [$actor,$company,'pazarama.archive_requested',json_encode(['from'=>$from,'untilExclusive'=>$bounds['untilExclusive']]),$time]);
        });
    }
}

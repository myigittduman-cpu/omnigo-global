<?php
declare(strict_types=1);
namespace Omnigo;

final class N11Connection
{
    public function __construct(private Database $db,private IntegrationVault $vault) {}

    public function save(int $company,array $input,int $userId): void
    {
        try {$credentials=N11Credentials::fromArray($input);}
        catch (\InvalidArgumentException $error) {Http::fail(400,$error->getMessage());}
        $expected=$input['version']??0;
        if (!is_int($expected) || $expected<0) Http::fail(400,'Bağlantı sürümünü kontrol edin.');
        self::assertReady($this->db);
        $config=[...($this->vault->get($company,'n11')??[]),'apiKey'=>$credentials->apiKey,'apiSecret'=>$credentials->apiSecret,
            'enabled'=>true,'revision'=>bin2hex(random_bytes(16))];
        $this->vault->save($company,$config,'n11',function(?array $previous) use($company,$expected,$userId,$credentials) {
            if ((int)($previous['version']??0)!==$expected) Http::fail(409,'Bağlantı değişti. Sayfayı yenileyin.');
            $this->queue($company);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,NULL,NULL,?,?)',
                [$userId,$company,'n11 okuma bağlantısı kaydedildi',json_encode(['mode'=>'read_only']),gmdate('c')]);
        });
    }

    public function retry(int $company): void
    {
        self::assertReady($this->db);
        $this->db->transaction(function() use($company) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if (!$this->vault->get($company,'n11')) Http::fail(400,'Önce bağlantı bilgilerini kaydedin.');
            $this->queue($company);
        });
    }

    private function queue(int $company): void
    {
        $job=$this->db->one("SELECT state,lease_until,next_run FROM jobs WHERE company_id=? AND provider='n11'".$this->db->lock(),[$company]);
        if (($job['state']??'')==='running' && ($job['lease_until']??0)>time()) Http::fail(409,'Okuma kontrolü sürüyor; tamamlandığında tekrar deneyin.');
        $next=max(time(),(int)($job['next_run']??0));
        (new SyncQueue($this->db))->enqueue($company,'n11');
        $this->db->run("UPDATE jobs SET state='pending',attempts=0,error=NULL,next_run=?,lease_token=NULL,lease_until=NULL WHERE company_id=? AND provider='n11'",[$next,$company]);
        $row=$this->db->one("SELECT id,payload FROM records WHERE company_id=? AND kind='sync_n11'",[$company]);
        $state=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
        $state['phase']='pending';$state['nextRequestAt']=$next;unset($state['failure']);
        $state['salesPublished']??=false;$state['sampleOnly']??=true;
        $payload=json_encode($state,JSON_THROW_ON_ERROR);
        if ($row) $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$row['id']]);
        else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_n11',$payload,gmdate('c')]);
    }

    public function status(int $company): array
    {
        $config=$this->vault->get($company,'n11');
        $row=$this->db->one("SELECT version FROM records WHERE company_id=? AND kind='integration_n11'",[$company]);
        $sync=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_n11'",[$company]);
        $state=$sync?json_decode($sync['payload'],true,32,JSON_THROW_ON_ERROR):[];
        return ['configured'=>(bool)$config,'sellerId'=>$state['sellerId']??$state['preview']['sellerId']??null,'version'=>(int)($row['version']??0),
            'sync'=>array_intersect_key($state,array_flip(['phase','checkedAt','periodStart','periodEnd','limit','preview','totalPages','sellerVerified','salesPublished','sampleOnly','failure','startDate','watermark','lastSuccessAt'])),
            'job'=>$this->db->one("SELECT state,error,next_run FROM jobs WHERE company_id=? AND provider='n11'",[$company])];
    }

    public static function assertReady(Database $db): void
    {
        if (!$db->mysql()) return;
        $column=$db->one("SHOW COLUMNS FROM records LIKE 'kind'");
        foreach(['integration_n11','sync_n11','order_n11','stage_n11'] as $kind) if (!str_contains($column['Type']??'',"'".$kind."'")) {
            Http::fail(503,'n11 için veritabanı güncellemesi uygulanmalı.');
        }
    }
}

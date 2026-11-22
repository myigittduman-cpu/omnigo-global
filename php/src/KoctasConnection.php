<?php
declare(strict_types=1);
namespace Omnigo;

/** Admin setup and recovery; account verification runs in the leased worker. */
final class KoctasConnection
{
    public function __construct(private Database $db,private IntegrationVault $vault){}
    public function status(int $company): array
    {
        $config=$this->vault->get($company);$row=$this->db->one("SELECT version FROM records WHERE company_id=? AND kind='integration_koctas'",[$company]);
        $sync=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_koctas'",[$company]);
        $state=$sync?json_decode($sync['payload'],true,64,JSON_THROW_ON_ERROR):[];
        return ['configured'=>(bool)$config,'shopId'=>$config['shopId']??null,'version'=>(int)($row['version']??0),'startDate'=>$config['startDate']??null,
            'sync'=>array_intersect_key($state,array_flip(['lastSuccessAt','lastPageAt','startDate','phase'])),
            'job'=>$this->db->one("SELECT state,error,next_run FROM jobs WHERE company_id=? AND provider='Koçtaş'",[$company])];
    }
    public function save(int $company,array $input,int $userId): void
    {
        $key=$input['apiKey']??'';$shop=$input['shopId']??null;$expected=$input['version']??0;
        if(!is_string($key)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$key)||!is_int($shop)||$shop<1||!is_int($expected)||$expected<0)Http::fail(400,'API anahtarını ve mağaza numarasını kontrol edin.');
        IntegrationSchema::assertReady($this->db);
        $current=$this->vault->get($company);
        if($current&&(int)$current['shopId']!==$shop)Http::fail(409,'Bu müşteriye başka mağazanın kayıtları karışmaması için mağaza numarası değiştirilemez. Doğru müşteri çalışma alanını seçin.');
        $start=$current['startDate']??(new \DateTimeImmutable('first day of this month 00:00:00',new \DateTimeZone('Europe/Istanbul')))->format('c');
        if(!$current)foreach((new Repository($this->db))->records($company,'sales',['role'=>'admin']) as $sale)if($sale['channel']==='Koçtaş'&&$sale['month']>=substr($start,0,7))Http::fail(409,'Bu dönem için elle girilmiş Koçtaş satış özeti var. Otomatik aktarımdan önce kaydı kontrol edin.');
        $this->vault->save($company,[...($current??[]),'apiKey'=>$key,'shopId'=>$shop,'startDate'=>$start,'enabled'=>true,'revision'=>bin2hex(random_bytes(16))],'koctas',function($previous)use($company,$expected,$userId,$shop){
            if((int)($previous['version']??0)!==$expected)Http::fail(409,'Bağlantı değişti. Sayfayı yenileyin.');
            $this->enqueue($company);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,NULL,NULL,?,?)',[$userId,$company,'Koçtaş bağlantısı kaydedildi',json_encode(['shopId'=>$shop,'access'=>'read_only']),gmdate('c')]);
        });
    }
    public function retry(int $company): void
    {
        $this->db->transaction(function()use($company){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if(!$this->vault->get($company))Http::fail(400,'Önce bağlantı bilgilerini kaydedin.');
            $this->enqueue($company);
        });
    }
    private function enqueue(int $company): void
    {
        $job=$this->db->one("SELECT * FROM jobs WHERE company_id=? AND provider='Koçtaş'".$this->db->lock(),[$company]);
        if(($job['state']??'')==='running'&&($job['lease_until']??0)>time())Http::fail(409,'Aktarım sürüyor. Tamamlandığında tekrar deneyin.');
        (new SyncQueue($this->db))->enqueue($company,'Koçtaş');
        $this->db->run("UPDATE jobs SET state='pending',attempts=0,error=NULL,next_run=?,lease_token=NULL,lease_until=NULL WHERE company_id=? AND provider='Koçtaş'",[max(time(),(int)($job['next_run']??0)),$company]);
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

final class MarketplaceConnection
{
    public const PROVIDERS=['pttavm'=>'PTT AVM','idefix'=>'idefix','trendyol'=>'Trendyol'];
    public function __construct(private Database $db,private IntegrationVault $vault,private string $provider)
    {if(!isset(self::PROVIDERS[$provider]))throw new \InvalidArgumentException('Pazaryeri geçersiz.');}
    public function status(int $company): array
    {
        $row=$this->db->one('SELECT version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$this->provider]);
        $sync=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$this->provider]);
        return ['configured'=>(bool)$row,'version'=>(int)($row['version']??0),'sync'=>$sync?json_decode($sync['payload'],true,32,JSON_THROW_ON_ERROR):[],
            'job'=>$this->db->one('SELECT state,error,next_run FROM jobs WHERE company_id=? AND provider=?',[$company,self::PROVIDERS[$this->provider]])];
    }
    public function save(int $company,array $input): void
    {
        $fields=match($this->provider){'idefix'=>['vendorId','apiKey','apiSecret'],'trendyol'=>['supplierId','apiKey','apiSecret'],default=>['apiKey','accessToken']};$values=[];
        foreach($fields as $field){$value=$input[$field]??null;if(!is_string($value)||!preg_match('/^[\x21-\x7e]{1,4096}$/D',trim($value)))Http::fail(400,'API bağlantı alanlarını kontrol edin.');$values[$field]=trim($value);}
        if($this->provider==='idefix'&&!preg_match('/^[1-9][0-9]{0,15}$/D',$values['vendorId']))Http::fail(400,'Satıcı numarasını kontrol edin.');
        if($this->provider==='trendyol'&&!preg_match('/^[1-9][0-9]{0,15}$/D',$values['supplierId']))Http::fail(400,'Satıcı numarasını kontrol edin.');
        $expected=$input['version']??null;if(!is_int($expected)||$expected<0)Http::fail(400,'Bağlantı sürümü geçersiz.');
        // Append the two new enum values before the vault transaction; existing kinds are retained.
        if($this->provider==='trendyol')MarketplaceConnectionSchema::install($this->db);
        $current=$this->vault->get($company,$this->provider)??[];
        $this->vault->save($company,[...$current,...$values,...($this->provider==='trendyol'?['startDate'=>$current['startDate']??(new \DateTimeImmutable('first day of this month 00:00:00',new \DateTimeZone('Europe/Istanbul')))->format(DATE_ATOM)]:[]),'connectedAt'=>$current['connectedAt']??time(),'enabled'=>true,'revision'=>bin2hex(random_bytes(16))],$this->provider,function($previous)use($company,$expected){
            if((int)($previous['version']??0)!==$expected)Http::fail(409,'Bağlantı değişti. Sayfayı yenileyin.');
            $this->enqueue($company);
        });
    }
    public function retry(int $company): void
    {
        $this->db->transaction(function()use($company){$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);if(!$this->vault->get($company,$this->provider))Http::fail(400,'Önce bağlantı bilgilerini kaydedin.');$this->enqueue($company);});
    }
    private function enqueue(int $company): void
    {
        $name=self::PROVIDERS[$this->provider];$job=$this->db->one('SELECT * FROM jobs WHERE company_id=? AND provider=?'.$this->db->lock(),[$company,$name]);
        if(($job['state']??'')==='running'&&($job['lease_until']??0)>time())Http::fail(409,'Bağlantı kontrolü sürüyor.');
        (new SyncQueue($this->db))->enqueue($company,$name);
        $this->db->run("UPDATE jobs SET state='pending',attempts=0,error=NULL,next_run=?,lease_token=NULL,lease_until=NULL WHERE company_id=? AND provider=?",[max(time(),(int)($job['next_run']??0)),$company,$name]);
        $row=$this->db->one('SELECT id,payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$this->provider]);
        $previous=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
        $keep=in_array($this->provider,['idefix','pttavm','trendyol'],true)?array_intersect_key($previous,array_flip(['startDate','lastSuccessAt','watermark','ordersPublished','orderCount','refundsVerified','refundsAsOf','returnRequests','returnRequestError'])):[];
        $payload=json_encode([...$keep,'phase'=>'pending','salesPublished'=>false]);
        if($row)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$row['id']]);
        else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_'.$this->provider,$payload,gmdate('c')]);
    }
}

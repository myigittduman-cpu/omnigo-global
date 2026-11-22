<?php
declare(strict_types=1);
namespace Omnigo;
final class MarketplaceProbeAdapter implements ScheduledReadAdapter
{
    public function __construct(private Database $db,private IntegrationVault $vault,private string $provider,private ?MarketplaceReadClient $reader=null){}
    public function nextDelay(): int{return 900;}
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $config=$this->vault->get($companyId,$this->provider);if(!$config||empty($config['enabled']))throw new SyncFailure(403);
        $zone=new \DateTimeZone('Europe/Istanbul');$end=new \DateTimeImmutable('now',$zone);$start=$end->modify('-1 day');
        try{$data=($this->reader??new MarketplaceReadClient())->orders($this->provider,$config,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),1,$deadline);$state=['phase'=>'verified','checkedAt'=>gmdate('c'),'sampleCount'=>count($data['items']),'sampleOnly'=>true,'salesPublished'=>false];}
        catch(\Throwable $error){$failure=$error;$state=['phase'=>'error','checkedAt'=>gmdate('c'),'code'=>$error instanceof SyncFailure?$error->httpStatus:0,'salesPublished'=>false];}
        $this->db->transaction(function()use($companyId,$config,$state){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$companyId]);$current=$this->vault->get($companyId,$this->provider);if(($current['revision']??null)!==$config['revision'])throw new SyncFailure(409);
            $row=$this->db->one('SELECT id FROM records WHERE company_id=? AND kind=?',[$companyId,'sync_'.$this->provider]);
            if($row)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[json_encode($state),gmdate('c'),$row['id']]);else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$companyId,'sync_'.$this->provider,json_encode($state),gmdate('c')]);
        });if(isset($failure))throw $failure;
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

final class HepsiburadaProbeAdapter implements ReadOnlyAdapter
{
    public function __construct(private Database $db,private IntegrationVault $vault,private ?HepsiburadaReadClient $reader=null) {}
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $config=$this->vault->get($companyId,'hepsiburada');
        if(!$config||!($config['enabled']??false))throw new SyncFailure(403);
        $row=$this->db->one("SELECT id,payload,version FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$companyId]);
        $state=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
        if(($state['phase']??null)==='review_ready')return;
        if(($state['nextRequestAt']??0)>time())throw new SyncFailure(429,$state['nextRequestAt']-time());
        try{
            $data=($this->reader??new HepsiburadaReadClient())->openOrders(HepsiburadaCredentials::fromArray($config),$deadline);
            $preview=HepsiburadaPreview::map($data['items'],$config['merchantId']);
        }catch(\Throwable $error){$state['failure']=HepsiburadaDiagnostics::failure($error);$this->persist($companyId,$row,$config,$state);throw $error;}
        $this->persist($companyId,$row,$config,['phase'=>'review_ready','checkedAt'=>gmdate('c'),'limit'=>10,'totalCount'=>$data['totalCount'],
            'preview'=>$preview,'merchantVerified'=>$preview['merchantVerified'],'salesPublished'=>false,'sampleOnly'=>true]);
    }
    private function persist(int $company,?array $row,array $config,array $state): void
    {
        $this->db->transaction(function()use($company,$row,$config,$state){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $fresh=$this->vault->get($company,'hepsiburada');
            $current=$this->db->one("SELECT id,version FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$company]);
            if(($fresh['revision']??null)!==($config['revision']??null)||($current['version']??0)!==($row['version']??0))throw new SyncFailure(409);
            $payload=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($current)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$current['id']]);
            else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_hepsiburada',$payload,gmdate('c')]);
        });
    }
}

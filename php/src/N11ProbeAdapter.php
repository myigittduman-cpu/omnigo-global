<?php
declare(strict_types=1);
namespace Omnigo;

/** Validate a bounded sample before enabling financial publication for a new provider. */
final class N11ProbeAdapter implements ReadOnlyAdapter
{
    public function __construct(private Database $db,private IntegrationVault $vault,private ?N11ReadClient $reader=null) {}
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $config=$this->vault->get($companyId,'n11');
        if(!$config||!($config['enabled']??false))throw new SyncFailure(403);
        $row=$this->db->one("SELECT id,payload,version FROM records WHERE company_id=? AND kind='sync_n11'",[$companyId]);
        $state=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
        if(($state['phase']??null)==='review_ready')return;
        if(($state['nextRequestAt']??0)>time())throw new SyncFailure(429,$state['nextRequestAt']-time());
        $end=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Istanbul')))->modify('-1 minute');
        $start=$end->modify('-7 days');
        try{
            $data=($this->reader??new N11ReadClient())->packages(N11Credentials::fromArray($config),$start->getTimestamp()*1000,$end->getTimestamp()*1000,0,$deadline);
            $preview=N11Preview::map($data['content']);
        }catch(\Throwable $error){$state['failure']=N11Diagnostics::failure($error);$this->persist($companyId,$row,$config,$state);throw $error;}
        $this->persist($companyId,$row,$config,['phase'=>'review_ready','checkedAt'=>gmdate('c'),'periodStart'=>$start->format(DATE_ATOM),
            'periodEnd'=>$end->format(DATE_ATOM),'limit'=>100,'totalPages'=>$data['totalPages'],'preview'=>$preview,
            'sellerVerified'=>false,'salesPublished'=>false,'sampleOnly'=>true]);
    }
    private function persist(int $company,?array $row,array $config,array $state): void
    {
        $this->db->transaction(function()use($company,$row,$config,$state){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $fresh=$this->vault->get($company,'n11');
            $current=$this->db->one("SELECT id,version FROM records WHERE company_id=? AND kind='sync_n11'",[$company]);
            if(($fresh['revision']??null)!==($config['revision']??null)||($current['version']??0)!==($row['version']??0))throw new SyncFailure(409);
            $payload=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($current)$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$current['id']]);
            else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,'sync_n11',$payload,gmdate('c')]);
        });
    }
}

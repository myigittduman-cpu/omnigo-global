<?php
declare(strict_types=1);
namespace Omnigo;

/** One queued sample per saved credential set; successful probes do not poll the provider. */
final class PazaramaProbeAdapter implements ReadOnlyAdapter
{
    public function __construct(private Database $db,private IntegrationVault $vault,
        private ?PazaramaAuthClient $auth=null,private ?PazaramaReadClient $reader=null) {}

    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $config=$this->vault->get($companyId,'pazarama');
        if (!$config || !($config['enabled']??false)) throw new SyncFailure(403);
        $row=$this->db->one("SELECT id,payload,version FROM records WHERE company_id=? AND kind='sync_pazarama'",[$companyId]);
        $state=$row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):[];
        if (($state['phase']??null)==='review_ready') return;
        if (($state['nextRequestAt']??0)>time()) throw new SyncFailure(429,$state['nextRequestAt']-time());
        $credentials=PazaramaCredentials::fromArray($config);
        $zone=new \DateTimeZone('Europe/Istanbul');
        $end=(new \DateTimeImmutable('now',$zone))->modify('-1 minute');
        $start=$end->modify('first day of this month')->setTime(0,0);
        if ($start->format('Y-m-d\TH:i')===$end->format('Y-m-d\TH:i')) $start=$start->modify('-1 day');
        $stage='authentication';
        try {
            $token=($this->auth??new PazaramaAuthClient())->authenticate($credentials,$deadline);
            $stage='orders';
            $orders=($this->reader??new PazaramaReadClient())->sample($token['accessToken'],$start->format('Y-m-d\TH:i'),$end->format('Y-m-d\TH:i'),$deadline);
            $stage='preview';
            $preview=PazaramaPreview::map($orders);
        } catch (\Throwable $error) {
            $state['failure']=PazaramaDiagnostics::failure($stage,$error);
            $this->persistState($companyId,$row,$config,$state);
            throw $error;
        } finally {unset($token);}
        $next=['phase'=>'review_ready','checkedAt'=>gmdate('c'),'periodStart'=>$start->format('Y-m-d\TH:i'),
            'periodEnd'=>$end->format('Y-m-d\TH:i'),'limit'=>20,'preview'=>$preview,'sellerVerified'=>false,
            'salesPublished'=>false,'sampleOnly'=>true];
        $this->persistState($companyId,$row,$config,$next);
    }

    private function persistState(int $companyId,?array $row,array $config,array $next): void
    {
        $this->db->transaction(function() use($companyId,$row,$config,$next) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$companyId]);
            $fresh=$this->vault->get($companyId,'pazarama');
            $current=$this->db->one("SELECT id,version FROM records WHERE company_id=? AND kind='sync_pazarama'",[$companyId]);
            if (($fresh['revision']??null)!==($config['revision']??null) || ($current['version']??0)!==($row['version']??0)) throw new SyncFailure(409);
            $payload=json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if ($current) $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$current['id']]);
            else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$companyId,'sync_pazarama',$payload,gmdate('c')]);
        });
    }
}

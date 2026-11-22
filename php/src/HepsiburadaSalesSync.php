<?php
declare(strict_types=1);
namespace Omnigo;

/** Independent retry queue: a claims outage cannot stop operational order imports. */
final class HepsiburadaSalesSync implements FixedCadenceReadAdapter
{
    public function __construct(private Database $db,private IntegrationVault $vault,private ?HepsiburadaReadClient $reader=null){}
    public function nextDelay():int{return 60;}
    public function syncReadOnly(int $companyId,int $deadline):void
    {
        $started=time();
        $config=$this->vault->get($companyId,'hepsiburada');
        if(empty($config['enabled'])||empty($config['ordersEnabled'])||empty($config['salesEnabled']))throw new SyncFailure(403);
        $row=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$companyId]);
        if(!$row)throw new SyncFailure(503);
        $state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$before=$state['financial']??[];$finance=$before;
        if(($finance['nextRequestAt']??0)>time())throw new SyncFailure(429,$finance['nextRequestAt']-time());
        $zone=new \DateTimeZone('Europe/Istanbul');$from=HepsiburadaClaims::date($config['salesStartDate']??null)->setTimezone($zone);
        if($from->format('d H:i:s')!=='01 00:00:00'||$from>new \DateTimeImmutable('now',$zone))throw new SyncFailure(422);
        $now=new \DateTimeImmutable('now',$zone);$through=$now->setTime((int)$now->format('H'),(int)$now->format('i'));
        $finance['scan']??=['from'=>$from->format(DATE_ATOM),'through'=>$through->format(DATE_ATOM),'stream'=>0,'offset'=>0,'refunds'=>[],'orders'=>[],'requestVersion'=>1,'requests'=>[]];
        $scan=$finance['scan'];$credentials=HepsiburadaCredentials::fromArray($config);$reader=$this->reader??new HepsiburadaReadClient();
        try{
            for($requests=0;$requests<20&&$scan['stream']<count(HepsiburadaClaims::STATUSES)&&time()<$deadline-8;$requests++){
                $status=HepsiburadaClaims::STATUSES[$scan['stream']];
                $page=$reader->claims($credentials,$status,$scan['offset'],$from->format('Y-m-d H:i'),(new \DateTimeImmutable($scan['through']))->format('Y-m-d H:i'),$deadline);
                foreach($page['items'] as $raw){
                    $claim=HepsiburadaClaims::map($raw,$status,$credentials->merchantId);
                    if(new \DateTimeImmutable($claim['orderedAt'])<$from)continue;
                    $scan['orders'][$claim['orderNumber']]=true;
                    if(($scan['requestVersion']??0)===1){
                        if(count($scan['requests'])<20000)$scan['requests'][]=ReturnRequestMapper::hepsiburada($claim);
                        else $scan['requestsInvalid']=true;
                    }
                    if($status==='Refunded'){
                        $previous=$scan['refunds'][$claim['id']]??null;if($previous!==null&&$previous!==$claim)throw new SyncFailure(409);
                        $scan['refunds'][$claim['id']]=$claim;
                    }
                }
                if(count($scan['refunds'])>10000||count($scan['orders'])>10000)throw new SyncFailure(503,3600);
                if($page['hasMore'])$scan['offset']+=10;else{$scan['stream']++;$scan['offset']=0;}
                if($scan['offset']>100000)throw new SyncFailure(503,3600);
            }
            $finance['scan']=$scan;$finance['nextRequestAt']=$started+60;unset($finance['error']);
            $this->commit($companyId,$config,$before,$finance);
        }catch(\Throwable $error){
            $this->failure($companyId,$config,$error);throw $error;
        }
    }
    private function commit(int $company,array $config,array $before,array $finance):void
    {
        $this->db->transaction(function()use($company,$config,$before,$finance){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $row=$this->db->one("SELECT id,payload FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$company]);
            $state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            if(($state['financial']['version']??0)!==($before['version']??0)||($this->vault->get($company,'hepsiburada')['revision']??null)!==$config['revision'])throw new SyncFailure(409);
            $scan=$finance['scan'];$finished=$scan['stream']===count(HepsiburadaClaims::STATUSES);
            if($finished){
                HepsiburadaClaimProjection::remember($state,$scan['refunds']);
                if(($scan['requestVersion']??0)===1&&empty($scan['requestsInvalid']))ReturnRequestSnapshot::publish($finance,$scan['requests'],$scan['from'],$scan['through']);
                elseif(!empty($scan['requestsInvalid']))$finance['returnRequestError']=['at'=>gmdate('c'),'message'=>'İade listesi sınırı aşıldı; son doğrulanan kayıtlar korunuyor.'];
                foreach(array_keys($scan['orders']) as $number){
                    if(!$this->db->one("SELECT id FROM recent_orders WHERE company_id=? AND provider='hepsiburada' AND external_id=?",[$company,(string)$number]))$state['pendingOrders'][$number]=true;
                }
                $ready=empty($state['pendingOrders'])&&empty($state['isolatedOrderCount'])&&($state['phase']??'')==='live'&&count($state['feedCompletedAt']??[])===count(HepsiburadaOrderDiscovery::FEEDS);
                if($ready){
                    $through=min(new \DateTimeImmutable($state['watermark']),new \DateTimeImmutable($scan['through']));
                    $refunds=array_replace($finance['refunds']??[],$scan['refunds']);
                    $finance['totals']=(new HepsiburadaSalesPublisher($this->db))->publish($company,$scan['from'],$through->format(DATE_ATOM),$refunds);
                    $finance['reportingReady']=true;
                    try{HepsiburadaReconciliationSource::publish($state,$scan['from'],$through->format(DATE_ATOM),$refunds);unset($state['reconciliationSource']['publishError']);}
                    catch(\Throwable $error){$state['reconciliationSource']['publishError']=['at'=>gmdate('c'),'code'=>$error instanceof SyncFailure?$error->httpStatus:500];}
                    $finance['refunds']=$refunds;$finance['lastSuccessAt']=gmdate('c');$finance['through']=$through->format(DATE_ATOM);$finance['from']=$scan['from'];
                    $state['salesPublished']=true;$state['refundsVerified']=true;$state['startDate']=$scan['from'];unset($finance['scan']);
                }
            }
            $finance['version']=($before['version']??0)+1;$state['financial']=$finance;
            $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[json_encode($state,JSON_THROW_ON_ERROR),gmdate('c'),$row['id']]);
        });
    }
    private function failure(int $company,array $config,\Throwable $error):void
    {
        $this->db->transaction(function()use($company,$config,$error){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if(($this->vault->get($company,'hepsiburada')['revision']??null)!==$config['revision'])return;
            $row=$this->db->one("SELECT id,payload FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$company]);$state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            $state['financial']['error']=['at'=>gmdate('c'),'code'=>$error instanceof SyncFailure?$error->httpStatus:500];
            $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[json_encode($state,JSON_THROW_ON_ERROR),gmdate('c'),$row['id']]);
        });
    }
}

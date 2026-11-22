<?php
declare(strict_types=1);
namespace Omnigo;

/** New orders run every minute; the independent historical/returns cursor keeps its own cadence. */
final class FrequentOrderAdapter implements FixedCadenceReadAdapter
{
    public function __construct(private Database $db,private IntegrationVault $vault,private string $provider,private DeliveryRepository $deliveries,private ScheduledReadAdapter $history,private ?RecentOrderReader $reader=null){}
    public function nextDelay(): int{return 60;}
    public function syncReadOnly(int $company,int $deadline): void
    {
        $config=$this->vault->get($company,$this->provider);if(!$config||empty($config['enabled']))throw new SyncFailure(403);
        $row=$this->db->one('SELECT id,payload,version FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$this->provider]);
        $state=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];$recent=$state['recentOrders']??[];
        if(($recent['nextRequestAt']??0)>time())throw new SyncFailure(429,$recent['nextRequestAt']-time());
        if(!isset($recent['scan'])||($recent['revision']??null)!==($config['revision']??null)){
            $recent['scan']=RecentOrderWindow::next($this->provider,$recent,$config,time());$recent['revision']=$config['revision'];
        }
        $requestStarted=time();$scan=$recent['scan'];$data=($this->reader??new RecentOrderReader())->page($this->provider,$config,$scan,$deadline);
        if($this->provider==='n11'&&$data['sellerId']!==null){if(isset($state['sellerId'])&&$state['sellerId']!==$data['sellerId'])throw new SyncFailure(422);$state['sellerId']=$data['sellerId'];}
        $recent['lastPageAt']=gmdate('c');$recent['nextRequestAt']=$requestStarted+60;$recent['intervalSeconds']=60;
        if($data['complete']){
            $recent['watermark']=min(time(),$scan['through']);$recent['lastSuccessAt']=gmdate('c');
            OrderReadCoverage::remember($recent,$scan['from'],$recent['watermark']);
            unset($recent['scan']);
        }
        else {if($scan['page']>=1000)throw new SyncFailure(422);$recent['scan']['page']++;}
        $state['recentOrders']=$recent;if($data['items'])$state['deliveryLastPageAt']=gmdate('c');
        (new RecentOrderStore($this->db,$this->deliveries))->commit($company,$this->provider,$config,$row,$state,$data['items'],$this->vault);
        // Do not make the new-order path wait for a long historical scan or its return pages.
        $historyDue=$this->history instanceof FinancialRefreshBatch?$this->history->dueAt($state):(int)($recent['historyNextAt']??0);
        if($historyDue<=time()&&$deadline-time()>=15){
            $historyStarted=$requestStarted;$historyDelay=900;$historyBackoff=false;
            try{$this->history->syncReadOnly($company,$deadline);$historyDelay=$this->history->nextDelay();}
            catch(\Throwable $error){if($error instanceof SyncFailure&&in_array($error->httpStatus,[401,403,429],true))throw $error;$historyDelay=max(300,$error instanceof SyncFailure?$error->retryAfter:0);$historyBackoff=true;}
            $this->db->transaction(function()use($company,$historyDelay,$historyStarted,$historyBackoff){
                $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                $row=$this->db->one('SELECT id,payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$this->provider]);$state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
                $base=(!$historyBackoff&&$this->history instanceof FinancialRefreshBatch)?$historyStarted:time();
                $state['recentOrders']['historyNextAt']=$base+$historyDelay;
                $state['recentOrders']['historyBackoff']=$historyBackoff;
                $this->db->run('UPDATE records SET payload=?,version=version+1,updated=? WHERE id=?',[json_encode($state,JSON_THROW_ON_ERROR),gmdate('c'),$row['id']]);
            });
        }
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

final class KoctasAdapter implements FixedCadenceReadAdapter
{
    public function nextDelay(): int{return 60;}
    public function __construct(private Database $db,private IntegrationVault $vault,private ?\Closure $request=null,private ?DeliveryRepository $deliveries=null) {}
    public function syncReadOnly(int $companyId,int $deadline): void
    {
        $config=$this->vault->get($companyId);
        if (!$config || !($config['enabled']??false)) throw new SyncFailure(403);
        $stateRow=$this->db->one("SELECT * FROM records WHERE company_id=? AND kind='sync_koctas'",[$companyId]);
        $state=$stateRow?json_decode($stateRow['payload'],true,16,JSON_THROW_ON_ERROR):[];
        if (time()<(int)($state['nextRequestAt']??0)) throw new SyncFailure(429,(int)$state['nextRequestAt']-time());
        if(!empty($config['revision'])&&($state['verifiedRevision']??null)!==$config['revision']){
            $account=$this->request?($this->request)('/api/account',['shop_id'=>$config['shopId']],$deadline):(new KoctasHttpClient($config['apiKey']))->get('/api/account',['shop_id'=>$config['shopId']],$deadline);
            if((int)($account['shop_id']??0)!==(int)$config['shopId'])throw new SyncFailure(403);
            $state['verifiedRevision']=$config['revision'];
        }
        // One full read is required to enrich older financial-only records with product details.
        $backfill=(new OrderImportQueue($this->db))->count($companyId,'koctas')>0||empty($state['reconciliationReference'])||($state['detailsVersion']??0)<1 || ($this->deliveries && (($state['deliveryVersion']??0)<1 || ($state['orderDetailsVersion']??0)<1));
        if ($backfill && empty($state['detailsBackfill'])) { $state['offset']=0;unset($state['scanEnd']); }
        $end=$state['scanEnd']??gmdate('c');
        $query=['shop_id'=>$config['shopId'],'max'=>100,'offset'=>$state['offset']??0,'sort'=>'dateCreated','order'=>'asc','order_tax_mode'=>'TAX_INCLUDED','start_date'=>$config['startDate'],'end_update_date'=>$end];
        if (!$backfill && !empty($state['watermark'])) $query['start_update_date']=gmdate('c',strtotime($state['watermark'])-300);
        $requestStarted=time();
        $result=$this->request ? ($this->request)('/api/orders',$query,$deadline) : (new KoctasHttpClient($config['apiKey']))->get('/api/orders',$query,$deadline);
        if (!is_array($result['orders']??null) || !is_int($result['total_count']??null) || count($result['orders'])>100 || $result['total_count']<0 || $result['total_count']>10000) throw new SyncFailure(422);
        $mapped=array_map([KoctasOrderMapper::class,'map'],$result['orders']);
        $deliveryItems=$this->deliveries?array_map(static function($raw){$delivery=DeliveryMapper::koctas($raw);return [...$delivery,'details'=>OrderDetailMapper::koctas($raw,$delivery),'shipmentItems'=>ShipmentOrderMapper::koctas($raw,$delivery)];},$result['orders']):[];
        $offset=(int)($state['offset']??0)+count($mapped); $complete=$offset>=$result['total_count'];
        if (!$complete && !$mapped) throw new SyncFailure(422);
        $windowFrom=strtotime($query['start_update_date']??$config['startDate']);
        $this->db->transaction(function() use($companyId,$mapped,$stateRow,$state,$end,$offset,$complete,$config,$backfill,$deliveryItems,$requestStarted,$windowFrom) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$companyId]);
            $current=$this->db->one("SELECT * FROM records WHERE company_id=? AND kind='sync_koctas'",[$companyId]);
            if (($current['version']??0)!==($stateRow['version']??0)) throw new SyncFailure(409);
            $all=$this->db->all("SELECT id,payload FROM records WHERE company_id=? AND kind='order_koctas'",[$companyId]); $byId=[];
            foreach($all as $row) $byId[json_decode($row['payload'],true)['externalId']]=$row;
            $queue=new OrderImportQueue($this->db);
            foreach($mapped as $index=>$order)$queue->attempt($companyId,'koctas',$order['externalId'],function()use($companyId,$order,$index,$deliveryItems,$byId){
                if(isset($deliveryItems[$index]))$this->deliveries->ingest($companyId,$deliveryItems[$index]);
                $this->store($companyId,'order_koctas',$order,$byId[$order['externalId']]['id']??null);
            });
            $next=['watermark'=>$complete?$end:($state['watermark']??null),'offset'=>$complete?0:$offset,'nextRequestAt'=>$requestStarted+60,'intervalSeconds'=>60,'lastPageAt'=>gmdate('c'),'lastSuccessAt'=>$complete?gmdate('c'):($state['lastSuccessAt']??null),'startDate'=>$config['startDate']];
            $next['detailsVersion']=$complete?1:($state['detailsVersion']??0);
            $next['detailsBackfill']=$backfill&&!$complete;
            if($this->deliveries) {$next['orderDetailsVersion']=$complete?1:($state['orderDetailsVersion']??0);$next['deliveryVersion']=$complete?1:($state['deliveryVersion']??0);$next['deliveryLastPageAt']=gmdate('c');}
            if (!$complete) $next['scanEnd']=$end;
            ReconciliationReference::incremental($state,$mapped,$deliveryItems,$backfill,empty($state['offset']),$complete,$config['startDate'],$end);
            foreach(['verifiedRevision','reconciliationReference','reconciliationDraft','reconciliationCheck','returnRequests','returnRequestError','returnRequestJob','orderReadCoverage'] as $field)if(isset($state[$field]))$next[$field]=$state[$field];
            if($complete&&$windowFrom!==false)OrderReadCoverage::remember($next,$windowFrom,(int)strtotime($end));
            IntegrationImportEvidence::completed($next,$config,$complete);
            $this->store($companyId,'sync_koctas',$next,$current['id']??null);
            if ($complete) $this->summarize($companyId,$config['startDate'],$end);
        });
    }
    private function store(int $company,string $kind,array $value,?int $id): void
    {
        $payload=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if ($id) $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$payload,gmdate('c'),$id]);
        else $this->db->run('INSERT INTO records(company_id,kind,payload,updated) VALUES(?,?,?,?)',[$company,$kind,$payload,gmdate('c')]);
    }
    private function summarize(int $company,string $startDate,string $through): void
    {
        $months=[];
        foreach(SalesCalendar::months($startDate,$through) as $month)$months[$month]=['channel'=>'Koçtaş','month'=>$month,'grossCents'=>0,'cancelCents'=>0,'returnCents'=>0,'orders'=>0,'source'=>'Koçtaş Mirakl API · KDV dahil ürün tutarı, kargo hariç · Sipariş ayına göre','integration'=>'koctas'];
        foreach ($months as &$summary) $summary['dailySales']=['from'=>$startDate,'through'=>$through,'days'=>[]];
        unset($summary);
        foreach($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='order_koctas'",[$company]) as $row) {
            $order=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
            if (!isset($months[$order['month']])) continue;
            foreach(['grossCents','cancelCents','returnCents','orders'] as $key) $months[$order['month']][$key]+=$order[$key];
            $day=SalesCalendar::day($order['createdAt']);
            $months[$order['month']]['dailySales']['days'][$day]??=SalesCalendar::ZERO;
            SalesCalendar::add($months[$order['month']]['dailySales']['days'][$day],$order);
        }
        $existing=[];
        foreach($this->db->all("SELECT id,payload FROM records WHERE company_id=? AND kind='sales'",[$company]) as $row) {
            $value=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
            if (($value['channel']??'')==='Koçtaş') {
                if (($value['integration']??'')!=='koctas' && isset($months[$value['month']])) throw new SyncFailure(409);
                $existing[$value['month']]=$row['id'];
            }
        }
        foreach($months as $month=>$summary) $this->store($company,'sales',$summary,$existing[$month]??null);
    }
}

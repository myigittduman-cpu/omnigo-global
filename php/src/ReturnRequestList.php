<?php
declare(strict_types=1);
namespace Omnigo;

final class ReturnRequestList
{
    public const PROVIDERS=['hepsiburada'=>'Hepsiburada','n11'=>'n11','idefix'=>'idefix','pazarama'=>'Pazarama','koctas'=>'Koçtaş','pttavm'=>'PTT AVM','trendyol'=>'Trendyol'];
    public function __construct(private Database $db,private ?OrderDetails $details=null){}
    public function listing(array $user,int $company,array $query,bool $all=false,bool $includeCancellations=false): array
    {
        $permissions=new CustomerPermissions($this->db,$company);
        $permissions->require($user,'returns');
        $admin=$user['role']==='admin';
        $flags=$admin?CustomerTableVisibility::ALL:CustomerTableVisibility::fromAccess($permissions->access($user),'returns');
        $includeCancellations=$includeCancellations&&$flags['cancellations'];
        $includeReturns=$flags['returns'];
        $provider=$query['provider']??'all';$status=$query['status']??'all';$search=trim((string)($query['search']??''));
        $type=(!$admin||$includeCancellations)?($query['type']??'all'):'return';
        if(!in_array($type,['all','return','cancellation'],true))Http::fail(400,'Talep türü geçersiz.');
        if((!$includeCancellations&&!$includeReturns)||($type==='cancellation'&&!$includeCancellations)||($type==='return'&&!$includeReturns)){
            Http::fail(403,'Bu talep türünü görüntüleme yetkiniz yok.');
        }
        if($type==='all'&&(!$includeCancellations||!$includeReturns))$type=$includeCancellations?'cancellation':'return';
        if(!in_array($provider,['all',...array_keys(self::PROVIDERS)],true)||!in_array($status,['all','pending','approved','refunded','rejected','cancelled','unknown'],true)||mb_strlen($search)>180)Http::fail(400,'İade filtresi geçersiz.');
        $page=filter_var($query['page']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000]]);if($page===false)Http::fail(400,'Sayfa geçersiz.');
        $items=[];$sources=[];
        $selectedTrendyol=(bool)array_filter((new Repository($this->db))->records($company,'channels',$user),fn($row)=>$row['name']==='Trendyol'&&!empty($row['selected']));
        foreach(($includeReturns?self::PROVIDERS:[]) as $key=>$label){
            if($provider!=='all'&&$provider!==$key)continue;
            $row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$key]);
            if($key==='trendyol'&&!$row&&!$selectedTrendyol)continue;
            $sync=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];
            $state=match($key){
                'hepsiburada'=>$sync['financial']??[],
                'trendyol'=>(new TrendyolClaimStore($this->db))->read($company),
                default=>$sync,
            };
            $snapshot=$state['returnRequests']??null;
            $hasOrderReturns=in_array($key,['pazarama','koctas','pttavm','trendyol'],true);
            $partial=$hasOrderReturns&&!$snapshot;$error=!empty($state['returnRequestError'])||($key!=='koctas'&&(!empty($state['failure'])||!empty($state['error'])||($state['phase']??'')==='error'));
            $last=$snapshot['checkedAt']??($partial?($sync['lastSuccessAt']??null):null);
            $asOf=$snapshot['through']??$last;$stale=$error||($last&&time()-min(strtotime($last),strtotime($asOf??$last))>1800);
            $source=['provider'=>$label,'key'=>$key,'lastSuccessAt'=>$last,'from'=>$snapshot['from']??($sync['startDate']??null),'through'=>$snapshot['through']??($sync['watermark']??null),
                'coverage'=>$partial?'completed_only':($key==='pazarama'?'order_line_returns':'claims'),'state'=>!$row&&!$snapshot?'unavailable':(!$last?'waiting':($stale?'stale':($partial?'partial':'ready')))];
            if($key==='pttavm'||($key==='trendyol'&&!$snapshot))$source['coverage']='order_status_returns';
            $sources[]=$source;
            $entries=$snapshot['items']??[];
            if($key==='hepsiburada')$entries=array_values(array_filter($entries,HepsiburadaClaims::reportsReturn(...)));
            if($key==='pttavm'){
                // PttavmOrderStore publishes here; legacy copies must not revive an old return status.
                $orders=RecentOrderSchema::available($this->db)
                    ?$this->db->all("SELECT payload FROM recent_orders WHERE company_id=? AND provider='pttavm'",[$company])
                    :$this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='order_pttavm'",[$company]);
                $entries=PttavmReturnProjection::orders(array_map(static fn($row)=>json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR),$orders));
            }
            $entries=NewReturnRecords::merge($this->db,$company,$key,$entries,$last,$snapshot['through']??null);
            if($key==='trendyol'&&$row&&RecentOrderSchema::available($this->db))$entries=$this->trendyolOrders($company,$entries);
            if($hasOrderReturns&&!in_array($key,['pttavm','trendyol'],true)){
                // Keep legacy confirmed refunds, without duplicating an order represented by RT11/line status.
                $represented=array_fill_keys(array_column($entries,'orderNumber'),true);
                foreach($this->completed($company,$key) as $legacy)if(!isset($represented[$legacy['orderNumber']]))$entries[]=$legacy;
            }
            if($type==='cancellation')continue;
            $entries=(new ReturnRequestEnrichment($this->db,$this->details))->apply($company,$key,$entries);
            foreach($entries as $item){
                $legacy=($item['status']??'')==='recorded_return';
                [$group,$text]=$legacy?['approved','Onaylanmış iade kaydı']:ReturnRequestMapper::status($key,$item['status']);
                if($status!=='all'&&$group!==$status)continue;
                if($search!==''&&mb_stripos(implode(' ',[$item['id'],$item['orderNumber'],$item['product']??'',$item['customerName']??'']),$search)===false)continue;
                $entry=[...$item,'type'=>'return','provider'=>$label,'providerKey'=>$key,'group'=>$group,'statusLabel'=>$text,'checkedAt'=>$last,
                    'partial'=>$legacy||($key==='trendyol'&&$item['status']==='Returned')];
                $items[]=[...$entry,'dossierRef'=>OperationTargets::returnRef($entry)];
            }
        }
        if($includeCancellations&&$type!=='return'&&in_array($provider,['all','pazarama'],true)&&in_array($status,['all','pending'],true)){
            foreach($this->cancellations($company) as $item){
                if($search!==''&&mb_stripos(implode(' ',[$item['id'],$item['orderNumber'],$item['product'],$item['customerName']]),$search)===false)continue;
                $items[]=$item;
            }
        }
        usort($items,ReturnRequestOrder::compare(...));
        $total=count($items);$page=min($page,max(0,(int)ceil($total/20)-1));
        return ['items'=>$all?$items:array_slice($items,$page*20,20),'total'=>$total,'page'=>$page,'pageSize'=>20,'hasMore'=>($page+1)*20<$total,'sources'=>$sources];
    }

    public function pendingCount(int $company,bool $includeCancellations=true,bool $includeReturns=true): int
    {
        $requests=[];
        foreach(($includeReturns?self::PROVIDERS:[]) as $key=>$label){
            if(!MarketplaceVisibility::visible($label))continue;
            if($key==='trendyol'){
                $state=(new TrendyolClaimStore($this->db))->read($company);
            }else{
                $row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$key]);
                if(!$row)continue;
                $state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
                if($key==='hepsiburada')$state=$state['financial']??[];
            }
            $snapshot=$state['returnRequests']??[];
            foreach(NewReturnRecords::merge($this->db,$company,$key,$snapshot['items']??[],$snapshot['checkedAt']??null,$snapshot['through']??null) as $item){
                if($key==='hepsiburada'&&!HepsiburadaClaims::reportsReturn($item))continue;
                if(ReturnRequestMapper::status($key,$item['status'])[0]==='pending')$requests[$key.':'.($item['claimId']??$item['id'])]=true;
            }
        }
        return count($requests)+($includeCancellations?count($this->cancellations($company)):0);
    }

    private function trendyolOrders(int $company, array $claims): array
    {
        $represented=[];
        foreach($claims as $item)$represented[$item['orderNumber']][$item['lineId']]=true;
        $rows=$this->db->all("SELECT payload FROM recent_orders WHERE company_id=? AND provider='trendyol'",[$company]);
        $orders=array_map(static fn($row)=>json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR),$rows);
        foreach(TrendyolReturnProjection::orders($orders) as $item){
            if(!isset($represented[$item['orderNumber']][$item['lineId']]))$claims[]=$item;
        }
        return $claims;
    }

    private function cancellations(int $company): array
    {
        return (new AttentionCancellations($this->db,new DeliveryCipher(dirname(__DIR__).'/storage/backup.key')))->requests($company);
    }
    private function completed(int $company,string $provider): array
    {
        $items=[];
        foreach($this->db->all('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'order_'.$provider]) as $row){
            $order=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            if(($order['returnCents']??0)<=0)continue;
            $items[]=['id'=>'order:'.$order['externalId'],'orderNumber'=>$order['externalId'],'lineId'=>'','status'=>'recorded_return','createdAt'=>null,
                'quantity'=>ReturnRequestEnrichment::completedQuantity($order),'amountCents'=>$order['returnCents'],'product'=>implode(' · ',array_column(array_filter($order['products']??[],static fn($p)=>($p['returnCents']??0)>0),'title')),'reason'=>''];
        }
        return $items;
    }
}

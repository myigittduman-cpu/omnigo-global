<?php
declare(strict_types=1);
namespace Omnigo;

/** Reads completed local snapshots; never calls a marketplace or modifies synchronization state. */
final class ReturnNotificationObserver
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function run(): void
    {
        $deadline=microtime(true)+3;
        $rows=$this->db->all("SELECT r.id,r.company_id,r.kind,r.payload,r.updated,n.id AS checkpoint_id,n.payload AS checkpoint FROM records r LEFT JOIN order_notifications n ON n.company_id=r.company_id AND n.event_type='checkpoint' AND n.provider=SUBSTR(r.kind,6) WHERE r.kind IN ('sync_hepsiburada','sync_n11','sync_idefix','sync_pazarama','sync_koctas','sync_pttavm','sync_trendyol') ORDER BY COALESCE(n.created,'') LIMIT 12");
        foreach($rows as $row){if(microtime(true)>$deadline)break;try{$this->observe($row);}catch(\Throwable $error){error_log('Omnigo iade bildirim taraması: '.get_class($error));}}
    }
    private function observe(array $row): void
    {
        $company=(int)$row['company_id'];$provider=substr($row['kind'],5);$state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        if($provider==='hepsiburada')$state=$state['financial']??[];
        $snapshot=$state['returnRequests']??null;
        if(!$snapshot&&!(in_array($provider,['pttavm','trendyol'],true)&&!empty($state['lastSuccessAt'])))return;
        $items=$snapshot['items']??[];
        if(in_array($provider,['pttavm','trendyol'],true)&&RecentOrderSchema::available($this->db)){
            $orders=$this->db->all('SELECT payload FROM recent_orders WHERE company_id=? AND provider=? LIMIT 20001',[$company,$provider]);
            if(count($orders)>20000)return;
            $orders=array_map(fn($r)=>json_decode($r['payload'],true,64,JSON_THROW_ON_ERROR),$orders);
            $items=$provider==='pttavm'?PttavmReturnProjection::orders($orders):TrendyolReturnProjection::orders($orders);
        }
        if(count($items)>20000)return;
        TransactionRetry::run($this->db,function()use($company,$provider,$items){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $key=hash('sha256','return-notification-cursor');
            $prior=$this->db->one("SELECT id,payload FROM order_notifications WHERE company_id=? AND provider=? AND order_key=? AND event_type='checkpoint'",[$company,$provider,$key]);
            $known=$prior?json_decode($prior['payload'],true,64,JSON_THROW_ON_ERROR):[];$next=$known;$changes=[];
            foreach($items as $item){
                if($provider==='hepsiburada'&&!HepsiburadaClaims::reportsReturn($item))continue;
                $id=(string)($item['id']??'');$status=(string)($item['status']??'');if($id===''||$status==='')continue;
                if($prior&&($known[$id]??null)!==$status)$changes[$id]=$item;
                $next[$id]=$status;
            }
            // At most 100 events per transaction; remaining changes retain their old baseline for retry.
            foreach(array_slice($changes,100) as $item){if(isset($known[$item['id']]))$next[$item['id']]=$known[$item['id']];else unset($next[$item['id']]);}
            foreach(array_slice($changes,0,100) as $item){
                [, $label]=ReturnRequestMapper::status($provider,(string)$item['status']);
                (new OperationalMailEvents($this->db,$this->cipher))->record($company,0,['events'=>['return_updated'],'provider'=>$provider,'section'=>'returns','statusLabel'=>$label,'isNewReturn'=>!array_key_exists($item['id'],$known),'claimId'=>$item['id'],'orderNumber'=>$item['orderNumber']??'','quantity'=>$item['quantity']??null,...array_intersect_key($item,array_flip(['lineId','amountCents','product']))]);
            }
            $payload=json_encode($next,JSON_THROW_ON_ERROR);$now=gmdate('c');
            if($prior)$this->db->run('UPDATE order_notifications SET payload=?,created=? WHERE id=?',[$payload,$now,$prior['id']]);
            else $this->db->run("INSERT INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,'checkpoint',?,?)",[$company,$provider,$key,$payload,$now]);
        });
    }
}

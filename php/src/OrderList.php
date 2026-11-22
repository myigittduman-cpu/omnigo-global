<?php
declare(strict_types=1);
namespace Omnigo;

/** Reads the existing marketplace snapshots; opening this page never calls a seller API. */
final class OrderList
{
    public const PAGE_SIZE=15;
    public const PROVIDERS=['koctas'=>'Koçtaş','n11'=>'n11','pazarama'=>'Pazarama','idefix'=>'idefix','hepsiburada'=>'Hepsiburada','pttavm'=>'PTT AVM','trendyol'=>'Trendyol'];
    public function __construct(private Database $db,private ?OrderDetails $details=null){}
    public function listing(array $user,int $company,array $input,bool $management=false): array
    {
        (new CustomerPermissions($this->db,$company))->require($user,'orders');$query=ShipmentSearch::query($input['query']??'');
        $provider=$input['provider']??'all';$page=filter_var($input['page']??0,FILTER_VALIDATE_INT);
        if(!in_array($provider,['all',...array_keys(self::PROVIDERS)],true)||$page===false||$page<0||$page>2000)Http::fail(400,'Sipariş filtresini kontrol edin.');
        if($management)Security::admin($user);
        $province=$management?($input['province']??'all'):'all';
        if($province==='')$province='all';
        if($province!=='all'&&!in_array($province,TurkeyCities::ALL,true))Http::fail(400,'Geçerli bir il seçin.');
        $dates=[];
        if(!$management||($input['from']??'')!==''||($input['to']??'')!==''){
            $from=Validation::date($input['from']??null);$to=Validation::date($input['to']??null);$zone=new \DateTimeZone('Europe/Istanbul');
            $start=new \DateTimeImmutable($from.' 00:00:00',$zone);$end=new \DateTimeImmutable($to.' 23:59:59',$zone);
            if($from>$to||(!$management&&$start->diff($end)->days>365))Http::fail(400,'Tarih aralığını kontrol edin.');
            $dates=[$start->getTimestamp(),$end->getTimestamp()];
        }
        $source='records';
        if(RecentOrderSchema::available($this->db)){
            $external=$this->db->mysql()?"JSON_UNQUOTE(JSON_EXTRACT(r.payload,'$.externalId'))":"JSON_EXTRACT(r.payload,'$.externalId')";
            $kind=$this->db->mysql()?"CONCAT('order_',a.provider)":"('order_'||a.provider)";
            // Completed financial snapshots take precedence; recent arrivals fill only missing orders.
            $source="(SELECT id,company_id,kind,payload,updated FROM records UNION ALL SELECT -a.id,a.company_id,$kind,a.payload,a.updated FROM recent_orders a WHERE NOT EXISTS (SELECT 1 FROM records r WHERE r.company_id=a.company_id AND r.kind=$kind AND $external=a.external_id)) order_rows";
        }
        $json=fn($field)=>$this->db->mysql()?"JSON_UNQUOTE(JSON_EXTRACT(payload,'$.$field'))":"JSON_EXTRACT(payload,'$.$field')";
        $date=$json('createdAt');
        // Normalize mixed UTC/+03 timestamps before sorting, without decrypting/scanning every order in PHP.
        $timestamp=$this->db->mysql()?"(TIMESTAMPDIFF(SECOND,'1970-01-01',STR_TO_DATE(SUBSTRING($date,1,19),'%Y-%m-%dT%H:%i:%s'))-CASE WHEN SUBSTRING($date,20,1) IN ('+','-') THEN (CAST(SUBSTRING($date,21,2) AS SIGNED)*3600+CAST(SUBSTRING($date,24,2) AS SIGNED)*60)*CASE WHEN SUBSTRING($date,20,1)='-' THEN -1 ELSE 1 END ELSE 0 END)":"CAST(strftime('%s',$date) AS INTEGER)";
        $kinds=array_map(fn($key)=>'order_'.$key,$provider==='all'?array_keys(self::PROVIDERS):[$provider]);
        $where='company_id=? AND kind IN ('.implode(',',array_fill(0,count($kinds),'?')).') AND '.$json('orders').'>0';
        if($dates)$where.=' AND '.$timestamp.' BETWEEN ? AND ?';
        $args=[$company,...$kinds,...$dates];
        $visibility=CustomerSensitiveVisibility::flags($this->db,$user,$company,'orders');
        $amount=fn($field)=>'CAST(COALESCE('.$json($field).',0) AS '.($this->db->mysql()?'SIGNED':'INTEGER').')';
        if(!$visibility['cancellations'])$where.=" AND UPPER(COALESCE(".$json('state').",'')) NOT IN ('CANCELED','CANCELLED','REFUSED') AND (".$amount('grossCents')."<=0 OR ".$amount('cancelCents')."<".$amount('grossCents').")";
        if(!$visibility['returns'])$where.=" AND UPPER(COALESCE(".$json('state').",'')) NOT IN ('RETURNED','REFUNDED') AND (".$amount('grossCents')."<=0 OR ".$amount('returnCents')."<".$amount('grossCents').")";
        return $this->db->transaction(function()use($user,$company,$page,$where,$args,$timestamp,$source,$query,$management,$province){
            if($query!==''||$province!=='all'){$result=(new OrderSearch($this->db,$this->details))->page($company,$source,$where,$args,$timestamp,$page,$query,$management,$province);$total=$result['total'];$page=$result['page'];$items=$result['items'];}
            else{
            $total=(int)$this->db->one('SELECT COUNT(*) AS n FROM '.$source.' WHERE '.$where,$args)['n'];$page=min($page,max(0,(int)ceil($total/self::PAGE_SIZE)-1));
            $rows=$this->db->all('SELECT id,kind,payload,updated FROM '.$source.' WHERE '.$where.' ORDER BY '.$timestamp.' DESC,id DESC LIMIT '.self::PAGE_SIZE.' OFFSET '.($page*self::PAGE_SIZE),$args);
            $items=array_map(function($row){$value=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);return ['id'=>(int)$row['id'],'provider'=>self::PROVIDERS[substr($row['kind'],6)],...array_intersect_key($value,array_flip(['externalId','createdAt','state','grossCents','cancelCents','returnCents','refundsVerified','products'])),'updatedAt'=>$row['updated']];},$rows);
            }
            if($this->details)$items=$this->details->summaries($company,$items,true,$management);
            $sync=[];
            foreach(self::PROVIDERS as $key=>$name){$row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$key]);$value=$row?json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR):[];if($row)$sync[]=['provider'=>$name,'lastSuccessAt'=>$value['recentOrders']['lastSuccessAt']??$value['watermark']??null,'running'=>isset($value['recentOrders'])?isset($value['recentOrders']['scan']):(isset($value['scanEnd'])||isset($value['scan']))];}
            return CustomerSensitiveVisibility::financial($this->db,$user,$company,['items'=>$items,'total'=>$total,'page'=>$page,'pageSize'=>self::PAGE_SIZE,'hasMore'=>($page+1)*self::PAGE_SIZE<$total,'sync'=>$sync],page:'orders');
        });
    }
}

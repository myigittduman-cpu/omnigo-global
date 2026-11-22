<?php
declare(strict_types=1);
namespace Omnigo;

/** Read local source facts only; no remote requests, queue jobs or business mutations. */
final class AttentionSources
{
    public const SINCE='2026-10-01';
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function seal(int $company,array $item):string{return $this->cipher->seal($company,'attention:'.$item['key'],$item);}
    public function open(int $company,array $row):array{return $this->cipher->open($company,'attention:'.$row['issue_key'],$row['payload']);}
    private array $returnRows=[];
    public function resolved(array $user,int $company,array $item):bool
    {
        if($item['type']==='inventory')return $this->inventoryResolved($company,$item['source']['productKey']);
        if(($item['source']['kind']??'')==='cancellation')return (new AttentionCancellations($this->db,$this->cipher))->resolved($company,$item['source']);
        if($item['type']==='shipments'){
            $row=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND id=?',[$company,$item['source']['id']]);if(!$row)return false;
            [,$payload,$status]=(new ShipmentDispatchStatus($this->cipher))->read($company,[$row])[0];
            return !empty($status['dispatchTracked'])?$status['fulfillmentStatus']==='shipped'
                :in_array(ShipmentFulfillment::source($payload),['shipped','delivered','completed','cancelled'],true);
        }
        if($item['type']==='returns'){
            $this->returnRows[$company]??=(new ReturnRequestList($this->db,new OrderDetails($this->db,$this->cipher)))->listing($user,$company,['status'=>'all'],true)['items'];
            foreach($this->returnRows[$company] as $row)if($row['dossierRef']===$item['source']['ref'])return in_array($row['group'],['approved','refunded','rejected','cancelled'],true);
        }
        if($item['type']==='payments')foreach((new Repository($this->db))->records($company,'payments',$user) as $row)if((string)$row['id']===(string)$item['source']['id'])return ($row['status']??'')==='paid'&&isset($row['actualCents'],$row['expectedCents'])&&$row['actualCents']===$row['expectedCents'];
        return false;
    }
    private function inventoryResolved(int $company,string $key):bool
    {
        $row=$this->db->one('SELECT * FROM inventory_products WHERE company_id=? AND product_key=?',[$company,$key]);
        if(!$row)return true;
        $settings=(new InventorySettings($this->db))->preferences($company);if($settings['mode']==='store')return true;
        $sources=(new InventorySourceStock($this->db))->forKeys($company,[$key]);
        $shared=(new InventorySharedStock($this->db))->forKeys($company,[$key]);
        $totals=InventoryTotals::calculate($settings['mode'],$row['quantity']===null?null:(int)$row['quantity'],$sources[$key]??[],$shared[$key]??[]);
        if(($totals['totalQuantity']??null)!==null&&$totals['totalQuantity']<=0)return true;
        $markets=(new InventoryMarketplaceAvailability($this->db))->forProducts($company,[$row],$sources,[])[$key]??[];
        return $markets&&!array_filter($markets,fn($market)=>$market['status']!=='active');
    }
    public function collect(array $user,int $company,string $type='all'):array
    {
        Security::admin($user);$this->returnRows=[];$items=[];$notes=[];
        foreach(['inventory','shipments','payments','returns'] as $source){
            if($type!=='all'&&$type!==$source)continue;
            if($source==='shipments'&&!CustomerModules::enabled($this->db,$company,$source))continue;
            try{[$rows,$warnings]=$this->$source($user,$company);array_push($items,...$rows);array_push($notes,...$warnings);}
            catch(\PDOException $error){if($error->getCode()!=='42S02'&&!str_contains($error->getMessage(),'no such table'))throw $error;$notes[]='Bu mağazada '.$source.' verileri henüz hazır değil.';}
        }
        usort($items,fn($a,$b)=>($b['priority']<=>$a['priority'])?:strcmp($a['due']??'9999',$b['due']??'9999')?:strcmp($a['key'],$b['key']));
        return ['items'=>$items,'notes'=>$notes,'checkedAt'=>gmdate('c')];
    }
    private static function issue(string $type,string $identity,string $title,string $detail,array $source,array $facts,int $priority=1,?string $due=null):array
    {
        return ['key'=>hash('sha256',$type.':'.$identity),'fingerprint'=>hash('sha256',json_encode($facts,JSON_THROW_ON_ERROR)),'type'=>$type,'title'=>$title,'detail'=>$detail,'source'=>$source,'priority'=>$priority,'due'=>$due];
    }
    private function inventory(array $user,int $company):array
    {
        $settings=(new InventorySettings($this->db))->preferences($company);if($settings['mode']==='store')return [[],[]];
        // The normal stocked view excludes sale-closed products; this control must inspect those too.
        [$sql,$params]=InventoryProductFilter::where($company,'all',$settings['mode']);
        $where="p.company_id=? AND NOT EXISTS (SELECT 1 FROM inventory_links k WHERE k.company_id=p.company_id AND k.source_product=p.product_key AND k.product_key<>p.product_key AND k.state='active') AND ".$sql;
        $where.=" AND (p.quantity>0 OR EXISTS(SELECT 1 FROM inventory_listings l WHERE l.company_id=p.company_id AND l.product_key=p.product_key AND l.source_quantity>0 AND l.remote_id NOT LIKE 'order-sku:%') OR EXISTS(SELECT 1 FROM inventory_links k JOIN inventory_listings l ON l.company_id=k.company_id AND l.product_key=k.source_product AND l.provider=k.provider WHERE k.company_id=p.company_id AND k.product_key=p.product_key AND k.state='active' AND l.source_quantity>0 AND l.remote_id NOT LIKE 'order-sku:%') OR EXISTS(SELECT 1 FROM inventory_shared_stock h WHERE h.company_id=p.company_id AND h.product_key=p.product_key AND h.manual_quantity+h.adjustment>0))";
        $rows=$this->db->all('SELECT p.* FROM inventory_products p WHERE '.$where.' ORDER BY p.sku,p.product_key LIMIT 501',[$company,...$params]);$limited=count($rows)>500;$rows=array_slice($rows,0,500);$keys=array_column($rows,'product_key');
        $snapshots=(new InventorySourceStock($this->db))->forKeys($company,$keys);$availability=(new InventoryMarketplaceAvailability($this->db))->forProducts($company,$rows,$snapshots,[]);$shared=(new InventorySharedStock($this->db))->forKeys($company,$keys);$items=[];
        foreach($rows as $row){$totals=InventoryTotals::calculate($settings['mode'],$row['quantity']===null?null:(int)$row['quantity'],$snapshots[$row['product_key']]??[],$shared[$row['product_key']]??[]);if(($totals['totalQuantity']??0)<=0)continue;
            $closed=array_values(array_filter($availability[$row['product_key']]??[],fn($market)=>in_array($market['status'],['closed','not_found','unmatched'],true)));if(!$closed)continue;
            $detail=implode(' · ',array_map(fn($market)=>$market['provider'].': '.$market['label'],$closed));
            $markets=array_map(fn($market)=>array_intersect_key($market,array_flip(['provider','status','label'])),$availability[$row['product_key']]??[]);
            $items[]=self::issue('inventory',$row['product_key'],$row['title'],$row['sku'].' · '.$detail,['view'=>'inventory','query'=>$row['sku'],'productKey'=>$row['product_key'],'marketplaces'=>$markets],array_map(fn($market)=>[$market['provider'],$market['status']],$closed));
        }
        return [$items,$limited?['Stok kontrolünde ilk 500 stoklu ürün incelendi; kalan ürünler bu özete dahil değil.']:[]];
    }
    private function shipments(array $user,int $company):array
    {
        [$scope,$params]=CustomerCities::scope($this->db,$company,['company_id=? AND removed_at IS NULL AND cancelled=0 AND completed=0 AND '.MarketplaceVisibility::sql(),[$company]]);
        $cutoffDate=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Istanbul')))->modify('-10 days');$cutoff=$cutoffDate->format('Y-m-d H:i:s');
        $rows=$this->db->all("SELECT * FROM shipment_entries WHERE $scope AND ordered_at>=? AND ordered_at<=? ORDER BY ordered_at,id LIMIT 501",[...$params,self::SINCE,substr($cutoff,0,10).'T23:59:59']);$items=[];
        foreach((new ShipmentDispatchStatus($this->cipher))->read($company,array_slice($rows,0,500)) as [$row,$payload,$state]){
            if($state['fulfillmentStatus']!=='pending')continue;
            if((new \DateTimeImmutable($row['ordered_at'],new \DateTimeZone('Europe/Istanbul')))->getTimestamp()>=$cutoffDate->getTimestamp()
                ||(empty($state['dispatchTracked'])&&(!empty($row['shipped'])||in_array($state['marketplaceStatus'],['shipped','delivered','completed','cancelled'],true))))continue;
            $items[]=self::issue('shipments',(string)$row['id'],'10 günü aşan sevkiyat bekleyişi',($payload['customerName']??$payload['recipientName']??'Sipariş').' · '.$row['provider'].' · '.($payload['productName']??'Sevkiyat kaydı'),['view'=>'shipments','id'=>(int)$row['id'],'month'=>$row['month'],'provider'=>$row['provider']],[$row['ordered_at'],$state['fulfillmentStatus']],2,substr($row['ordered_at'],0,10));
        }
        return [$items,count($rows)>500?['En eski 500 sevkiyat bekleyişi incelendi; kalanlar özete dahil değil.']:[]];
    }
    private function payments(array $user,int $company):array
    {
        $today=(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');$items=[];
        foreach((new Repository($this->db))->records($company,'payments',$user) as $row){
            if(($row['expectedDate']??'')<self::SINCE)continue;
            $paid=($row['status']??'')==='paid';$difference=$paid&&(isset($row['actualCents'],$row['expectedCents']))?$row['actualCents']-$row['expectedCents']:0;
            if(!$difference&&($paid||empty($row['expectedDate'])||$row['expectedDate']>=$today))continue;
            $items[]=self::issue('payments',(string)$row['id'],$difference?'Ödeme tutarı farkı':'Ödeme tarihi geçti',($row['channel']??'Hakediş').' · '.($difference?'Beklenen ve hesaba geçen tutarlar farklı.':'Banka geçişi henüz teyit edilmedi.'),['view'=>'payments','id'=>$row['id'],...($difference?['differenceCents'=>$difference,'expectedCents'=>$row['expectedCents'],'actualCents'=>$row['actualCents']]:[])],[$row['status'],$row['expectedDate'],$row['expectedCents'],$row['actualCents']??null],2,$row['expectedDate']);
        }
        return [$items,[]];
    }
    private function returns(array $user,int $company):array
    {
        $result=(new ReturnRequestList($this->db,new OrderDetails($this->db,$this->cipher)))->listing($user,$company,['status'=>'pending'],true);$items=[];
        $current=array_values(array_filter($result['items'],fn($row)=>substr($row['createdAt']??'',0,10)>=self::SINCE));
        foreach(array_slice($current,0,500) as $row)$items[]=self::issue('returns',$row['dossierRef'],'İşlem bekleyen iptal / iade talebi',$row['provider'].' · Sipariş '.$row['orderNumber'].' · '.$row['statusLabel'],['view'=>'returns','kind'=>'return','provider'=>$row['providerKey'],'ref'=>$row['dossierRef']],[$row['status'],$row['quantity'],$row['reason']],3,substr($row['createdAt'],0,10));
        $notes=$result['total']>500?['İlk 500 bekleyen iade talebi gösterilebilir.']:[];
        $uncertain=array_filter($result['sources'],fn($source)=>$source['state']!=='unavailable'&&!in_array($source['state'],['ready','partial'],true));
        if($uncertain)$notes[]=implode(', ',array_column($uncertain,'provider')).': iade talebi güncelliği henüz doğrulanamadı. Boş liste, talep olmadığı anlamına gelmez.';
        return [[...$items,...(new AttentionCancellations($this->db,$this->cipher))->pending($company)],$notes];
    }
}

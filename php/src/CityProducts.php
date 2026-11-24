<?php
declare(strict_types=1);
namespace Omnigo;

/** A product-level view of the same dispatchable packages as the city delivery list. */
final class CityProducts
{
    private const PRIORITY_CITIES=['Kayseri','Kırşehir','Nevşehir','Eskişehir','Kırıkkale'];
    private const PAGE_SIZE=10;

    public function __construct(private Database $db,private DeliveryCipher $cipher,private bool $showCancellationMark=false){}

    public function listing(int $company,array $query): array
    {
        $page=filter_var($query['page']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100000]]);
        if($page===false)Http::fail(400,'Sayfa numarası geçersiz.');
        $read=fn()=>$this->read($company,$query,$page);
        if($this->db->pdo->inTransaction())return $read();
        if($this->db->mysql())$this->db->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        return $this->db->transaction($read);
    }

    private function read(int $company,array $query,int $page): array
    {
        $scope=DeliveryFilters::storeScope($this->db,$company,true);
        $configured=array_intersect($scope['allowedCities'],DeliveryMapper::CITIES);
        $cities=[...array_intersect(self::PRIORITY_CITIES,$configured),...array_diff($configured,self::PRIORITY_CITIES)];
        $byOrder=($query['group']??'')==='order';
        $sort=$query['sort']??'default';
        if(!in_array($sort,['default','newest','oldest','city','recipient'],true)||(!$byOrder&&$sort!=='default')){
            Http::fail(400,'Sipariş listesinden geçerli bir sıralama seçin.');
        }
        $export=($query['export']??'')==='1';
        if($export&&!$byOrder)Http::fail(400,'Çıktı için sipariş görünümünü seçin.');
        $city=$query['city']??($byOrder?'all':($cities[0]??null));
        if($city!==null&&!($byOrder&&$city==='all')){
            if(!in_array($city,DeliveryMapper::CITIES,true))Http::fail(400,'Teslimat ilini listeden seçin.');
            if(!in_array($city,$cities,true))Http::fail(403,'Bu il mağazanın sevkiyat kapsamında bulunmuyor.');
        }
        $revision=$this->revision($company,$cities,$city,$page,$scope['hiddenProviders'],$byOrder,$sort);
        if(!$export&&($query['ifRevision']??null)===$revision)return ['unchanged'=>true,'revision'=>$revision];

        $read=fn(?array $ids=null)=>$this->packages($company,$city,$scope,$cities,$ids,$this->showCancellationMark&&!$export);
        [$total,$items]=$byOrder?CityProductOrders::page($read,$page,self::PAGE_SIZE,$export,$sort):CityProductOrders::linePage($read(),$page,self::PAGE_SIZE);
        $scopeInfo=['cities'=>$cities,'city'=>$city];
        if($byOrder)$scopeInfo['sort']=$sort;
        if($export)return [...$scopeInfo,'items'=>$items,'page'=>0,'pageSize'=>$total,
            'total'=>$total,'hasMore'=>false,'revision'=>$revision];
        $lastPage=intdiv(max(0,$total-1),self::PAGE_SIZE);
        if($page>$lastPage){
            $page=$lastPage;
            $revision=$this->revision($company,$cities,$city,$page,$scope['hiddenProviders'],$byOrder,$sort);
        }
        return [...$scopeInfo,'items'=>$items,'page'=>$page,
            'pageSize'=>self::PAGE_SIZE,'total'=>$total,'hasMore'=>$page<$lastPage,'revision'=>$revision];
    }

    private function packages(int $company,?string $city,array $scope,array $cities,?array $ids,bool $showCancellationMark): \Generator
    {
        if($city===null||!$cities)return;
        $scope['allowedCities']=$cities;
        $list=new DeliveryOperationalList($this->db,new DeliveryRepository($this->db,$this->cipher));
        foreach($list->packages($company,'all','all',true,new DeliveryFilters($scope),forProductRows:true,city:$city==='all'?null:$city,ids:$ids) as [$order,$products,$route]){
            if(!in_array($route,$cities,true))continue;
            // Sort by stable line identity, independently of pending/shipped state.
            usort($products,static fn($first,$second)=>strcmp($first['rowKey'],$second['rowKey']));
            $contact=self::contact($order);
            if($showCancellationMark&&OperationalCancellationMark::remainingOrder($order))$contact['cancellationMark']='partial';
            if($city==='all')$contact['orderKey']=hash('sha256',json_encode([$order['provider'],$order['orderNumber']?:$order['externalId']],JSON_THROW_ON_ERROR));
            yield [$contact,$products,$order['id']];
        }
    }

    private static function contact(array $order): array
    {
        $details=$order['details']??[];
        $recipient=(string)$order['recipientName'];
        $phone=(string)(($details['phone']??'')?:$order['phone']);
        $address=(string)(($details['deliveryAddress']??'')?:$order['address']);
        $district=DeliveryDistrict::resolve((string)$order['city'],[$details['district']??'',$order['district']??''],$address,(string)$order['address']);
        $columns=['recipientName'=>$recipient,'phone'=>$phone,'recipientEmail'=>$details['recipientEmail']??'',
            'province'=>$order['city'],'district'=>$district];
        return ['orderKey'=>DeliveryDispatchScope::key($order),'orderedAt'=>(string)(($details['orderedAt']??'')?:$order['createdAt']),
            'recipientName'=>$recipient,'phone'=>$phone,'address'=>ShipmentAddress::clean($address,$columns),
            'city'=>$order['city'],'district'=>$district];
    }

    private function revision(int $company,array $cities,?string $city,int $page,array $hidden,bool $byOrder,string $sort): string
    {
        $orders=$this->db->one('SELECT COUNT(*) AS n,COALESCE(SUM(version),0) AS v FROM delivery_orders WHERE company_id=?',[$company]);
        // A return approval can affect the delivery projection before the next order import.
        $keys=array_map(static fn($provider)=>'sync_'.$provider,array_keys(ReturnRequestList::PROVIDERS));
        $marks=implode(',',array_fill(0,count($keys),'?'));
        $sources=$this->db->all("SELECT id,version FROM records WHERE company_id=? AND kind IN ($marks) ORDER BY id",[$company,...$keys]);
        return hash('sha256',json_encode([$byOrder?'city-order-products-v4:'.$sort:'delivery-products-v4',$company,$cities,$city,$page,$hidden,
            $this->showCancellationMark,ShipmentRevision::read($this->db,$company),$orders,$sources],JSON_THROW_ON_ERROR));
    }
}

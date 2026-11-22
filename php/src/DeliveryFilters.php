<?php
declare(strict_types=1);
namespace Omnigo;
final class DeliveryFilters
{
    private ShipmentSearch $search;
    private ?array $cities;
    private ?array $allowedCities;
    private array $hiddenProviders;

    /** Keep store display settings consistent across delivery rows, product rows and counts. */
    public static function storeScope(Database $db,int $company,bool $visibleOnly): array
    {
        $hidden=[];
        if($visibleOnly){
            foreach($db->all("SELECT payload FROM records WHERE company_id=? AND kind='channels'",[$company]) as $row){
                $channel=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
                if(isset($channel['name'])&&!CustomerMarketplaces::shown($channel))$hidden[]=$channel['name'];
            }
        }
        sort($hidden,SORT_STRING);
        return ['allowedCities'=>[...CustomerCities::read($db,$company),'Diğer / Kontrol'],'hiddenProviders'=>$hidden];
    }

    public function __construct(private array $values=[])
    {
        $this->allowedCities=$values['allowedCities']??null;
        $this->hiddenProviders=$values['hiddenProviders']??[];
        $this->cities=DeliveryCities::parse($values['cities']??null);
        if($this->cities!==null&&$this->allowedCities!==null&&array_diff($this->cities,$this->allowedCities))Http::fail(400,'Seçili müşteri için tanımlı şehirlerden seçim yapın.');
        $this->values=['query'=>ShipmentSearch::query($values['query']??''),'from'=>$values['from']??'','to'=>$values['to']??''];
        foreach(['from','to'] as $key)if($this->values[$key]!==''){
            $value=$this->values[$key];
            if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))Http::fail(400,'Sipariş tarihi filtresini kontrol edin.');
            $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if(!$day||$day->format('Y-m-d')!==$value)Http::fail(400,'Sipariş tarihi filtresini kontrol edin.');
        }
        if($this->values['from']&&$this->values['to']&&$this->values['from']>$this->values['to'])Http::fail(400,'Bitiş tarihi başlangıçtan önce olamaz.');
        $this->search=new ShipmentSearch($this->values['query']);
    }
    public function matchesCity(string $city): bool{return $this->cities===null||in_array($city,$this->cities,true);}
    public function matches(array $order,bool $includeCity=true): bool
    {
        if(in_array($order['provider']??'',$this->hiddenProviders,true))return false;
        if($this->allowedCities!==null&&!in_array($order['city']??'',$this->allowedCities,true))return false;
        if($includeCity&&!$this->matchesCity($order['city']??''))return false;
        $day=!empty($order['createdAt'])?SalesCalendar::day($order['createdAt']):'';
        if(($this->values['from']&&$day<$this->values['from'])||($this->values['to']&&(!$day||$day>$this->values['to'])))return false;
        return $this->search->matches(['customerName'=>$order['buyerName']??'','recipientName'=>$order['recipientName']??'',
            'phone'=>$order['phone']??'','deliveryAddress'=>$order['address']??'','province'=>$order['city']??'',
            'productName'=>($order['orderNumber']??'').' '.implode(' ',array_map(fn($p)=>($p['title']??'').' '.($p['sku']??''),$order['queueProducts']??$order['products']??[]))]);
    }
}

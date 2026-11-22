<?php
declare(strict_types=1);
namespace Omnigo;

/** A fresh source snapshot is bound to the proposed status; concurrent changes require another confirmation. */
final class ShipmentStatusConfirmation
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public static function required(array $input,bool $bulk=false): void
    {
        $items=$bulk?($input['items']??[]):[$input];
        foreach($items as $item){
            if($item instanceof \stdClass)$item=(array)$item;
            if(!is_string($item['confirmation']??null)||!preg_match('/^[a-f0-9]{64}$/D',$item['confirmation']))
                Http::fail(428,'Önce pazaryeri durumunu ve seçtiğiniz yeni durumu onaylayın. Sayfayı yenileyip tekrar deneyin.');
        }
    }
    public static function fingerprint(array $row,array $payload,string $value): string
    {
        return hash('sha256',json_encode([(int)$row['id'],(int)$row['version'],$row['source_key']??null,ShipmentSourceStatus::value($payload),ShipmentStatus::resolve($row),$value],JSON_THROW_ON_ERROR));
    }
    public function verify(int $company,array $row,string $value,string $confirmation): void
    {
        $payload=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);
        if(!hash_equals(self::fingerprint($row,$payload,$value),$confirmation))Http::fail(409,'Siparişin durumu onay ekranı açıldıktan sonra değişti. Güncel durumu tekrar kontrol edip onaylayın.');
    }
    public function preview(array $user,int $company,array $selection,array $input): array
    {
        ShipmentAccess::company($user,$company);
        $value=$input['value']??null;$ids=$input['ids']??null;
        ShipmentCustomerVisibility::requireStatus($user,$value);
        if(!in_array($value,['','cancelled','shipped','invoiced','completed'],true)||!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>10)Http::fail(400,'Durum ve satır seçimini kontrol edin.');
        foreach($ids as $id)if(!is_int($id)||$id<1)Http::fail(400,'Satır seçimi geçersiz.');
        if(count(array_unique($ids))!==count($ids))Http::fail(400,'Aynı satır tekrar seçilemez.');
        [$scope,$params]=CustomerCities::scope($this->db,$company,ShipmentRepository::scope($company,$selection));
        $rows=$this->db->all('SELECT * FROM shipment_entries WHERE '.$scope.' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id',[...$params,...$ids]);
        if(count($rows)!==count($ids))Http::fail(404,'Seçilen kayıtlardan biri bu tabloda bulunamadı.');
        $flags=CustomerSensitiveVisibility::flags($this->db,$user,$company,'shipments');
        $items=[];
        foreach((new ShipmentDispatchStatus($this->cipher))->read($company,$rows) as [$row,$payload,$status]){
            ShipmentCustomerVisibility::requireVisible($user,$row,$payload);
            if(!ShipmentCustomerVisibility::readable($row,$payload,false,!$flags['returns']))Http::fail(404,'Sevkiyat kaydı bulunamadı.');
            $source=ShipmentSourceStatus::value($payload);
            $items[]=['id'=>(int)$row['id'],'version'=>(int)$row['version'],'provider'=>$row['provider'],
                'customerName'=>$payload['customerName']??'','productName'=>$payload['productName']??'',
                'marketplaceStatusText'=>$source===''?'Pazaryeri bilgisi yok':ShipmentFulfillment::label($source),
                'currentStatusText'=>ShipmentFulfillment::label($status['displayRowStatus']),
                'confirmation'=>self::fingerprint($row,$payload,$value)];
        }
        return ['value'=>$value,'targetStatusText'=>ShipmentFulfillment::label($value),'items'=>$items];
    }
}

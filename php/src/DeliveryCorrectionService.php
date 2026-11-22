<?php
declare(strict_types=1);
namespace Omnigo;

final class DeliveryCorrectionService
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    private function order(int $company,int $id): array
    {
        $row=$this->db->one('SELECT * FROM delivery_orders WHERE company_id=? AND id=?',[$company,$id]);if(!$row)Http::fail(404,'Sipariş bulunamadı.');
        MarketplaceVisibility::requireVisible($row['provider']);return (new DeliveryRepository($this->db,$this->cipher))->decode($row);
    }
    private function operational(int $company,int $id): array
    {
        return (new DeliveryRepository($this->db,$this->cipher))->operational($this->db->one('SELECT * FROM delivery_orders WHERE company_id=? AND id=?',[$company,$id]));
    }
    public function read(array $user,int $company,int $id): array
    {
        Security::admin($user);$order=$this->order($company,$id);
        if(!($this->operational($company,$id)['active'])||!empty($order['details']['superseded']))Http::fail(409,'Sipariş artık aktif değil. Listeyi yenileyin.');
        $operational=$this->operational($company,$id);
        return ['id'=>$id,'version'=>$order['version'],'orderNumber'=>$order['orderNumber'],'provider'=>$order['provider'],'fields'=>DeliveryCorrectionFields::describe($operational),'issues'=>$operational['issues']];
    }
    public function save(array $user,int $company,int $id,array $input): array
    {
        Security::admin($user);
        return $this->db->transaction(function()use($user,$company,$id,$input){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);$order=$this->order($company,$id);
            if(!is_int($input['version']??null)||$input['version']!==$order['version'])Http::fail(409,'Sipariş değişmiş. Formu kapatıp güncel kayıtla yeniden açın.');
            if(!($this->operational($company,$id)['active'])||!empty($order['details']['superseded']))Http::fail(409,'İptal edilen veya tamamlanan sipariş düzenlenemez.');
            $operational=$this->operational($company,$id);
            if(!$operational['issues']&&empty($order['manualCorrections']))Http::fail(409,'Bu siparişte tamamlanacak eksik bilgi yok.');
            $changes=DeliveryCorrectionFields::parse($operational,$input['fields']??null);
            if(!$changes)return ['saved'=>false,'ready'=>!$order['issues'],'city'=>$order['city'],'remaining'=>$order['issues']];
            $corrections=[...($order['manualCorrections']??[]),...$changes];$value=DeliveryCorrections::apply($order,$corrections);
            $shipments=new DeliveryCorrectionShipments($this->db,$this->cipher);
            if($value['city']==='Diğer / Kontrol'&&$order['city']!=='Diğer / Kontrol'&&$shipments->hasRows($company,$order))Http::fail(409,'Siparişin mevcut sevkiyat kaydı var. Kapsam dışına taşıma için önce bu kaydın kontrol edilmesi gerekiyor; bilgiler değiştirilmedi.');
            $corrected=(new DeliveryFulfillment($this->db,$this->cipher))->project($company,$value);
            $items=$shipments->items($company,$corrected);
            $force=[];foreach(array_keys($changes) as $field){if(isset(DeliveryCorrections::CONTACT_MAP[$field]))$force[]=DeliveryCorrections::CONTACT_MAP[$field];if(str_starts_with($field,'productTitle:'))$force[]='productName';if(str_starts_with($field,'productQuantity:'))$force[]='quantity';}
            foreach($items as &$item){$item['restoreMissing']=true;$item['correctionFields']=$force;}unset($item);
            foreach(['id','version','documentId','updated'] as $field)unset($value[$field]);
            $value['shipmentItems']=$items;
            (new DeliveryRepository($this->db,$this->cipher))->ingest($company,$value,false);
            $shipments->assertTransferred($company,$value['provider'],$items);
            // The API ingestor applies the same correction on every retry. No source status is locked.
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,NULL,?,?)',[$user['id'],$company,'delivery.corrected',$id,json_encode(['fields'=>array_keys($changes)]),gmdate('c')]);
            return ['saved'=>true,'ready'=>!$corrected['issues'],'city'=>$value['city'],'remaining'=>$corrected['issues']];
        });
    }
}

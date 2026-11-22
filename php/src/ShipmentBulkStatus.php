<?php
declare(strict_types=1);
namespace Omnigo;

/** Version-checked, atomic status change for at most one visible page. */
final class ShipmentBulkStatus
{
    public function __construct(private Database $db,private ?DeliveryCipher $cipher=null){}
    public function save(array $user,int $company,array $selection,array $input): array
    {
        ShipmentAccess::company($user,$company);$value=$input['value']??null;$items=$input['items']??null;
        ShipmentCustomerVisibility::requireStatus($user,$value);
        if($value==='delivered')$value='completed';
        if($value==='review')Http::fail(400,'Elle sevkiyat teyidi kaldırıldı.');
        if(!in_array($value,ShipmentStatus::VALUES,true)||!is_array($items)||!array_is_list($items)||count($items)<1||count($items)>10)Http::fail(400,'Açık sayfadan en fazla 10 satır seçin.');
        $versions=[];$confirmations=[];
        foreach($items as $item){
            if($item instanceof \stdClass)$item=(array)$item;
            if(!is_array($item)||!is_int($item['id']??null)||$item['id']<1||!is_int($item['version']??null)||$item['version']<1||isset($versions[$item['id']]))Http::fail(400,'Satır seçimi geçersiz.');
            $versions[$item['id']]=$item['version'];
            if(isset($item['confirmation']))$confirmations[$item['id']]=$item['confirmation'];
        }
        [$city,$provider,$month]=ShipmentRepository::selection(['city'=>$selection[0]??null,'provider'=>$selection[1]??null,'month'=>$selection[2]??null],true);
        return TransactionRetry::run($this->db,function()use($user,$company,$city,$provider,$month,$versions,$value,$confirmations){
            [$sql,$params]=ShipmentRepository::scope($company,[$city,$provider,$month]);
            $sql.=' AND id IN ('.implode(',',array_fill(0,count($versions),'?')).')';array_push($params,...array_keys($versions));
            $rows=$this->db->all('SELECT * FROM shipment_entries WHERE '.$sql.' ORDER BY id'.$this->db->lock(),$params);
            if(count($rows)!==count($versions))Http::fail(404,'Seçilen kayıtlardan biri bu tabloda bulunamadı.');
            foreach($rows as $row)if((int)$row['version']!==$versions[(int)$row['id']])Http::fail(409,'Bir kayıt değişmiş. Hiçbir satır güncellenmedi; liste yenilendi.');
            $cipher=$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');
            $projection=new ShipmentDispatchStatus($cipher);
            if($user['role']==='client')foreach($projection->read($company,$rows) as [$row,$payload]){
                ShipmentCustomerVisibility::requireVisible($user,$row,$payload);
            }
            foreach($rows as $row)if(isset($confirmations[$row['id']]))(new ShipmentStatusConfirmation($this->db,$cipher))->verify($company,$row,$value,$confirmations[$row['id']]);
            $saved=[];$dispatched=[];$now=gmdate('c');
            foreach($rows as $row){
                $id=(int)$row['id'];$before=['rowStatus'=>ShipmentStatus::resolve($row),'cancelled'=>(bool)$row['cancelled'],'shipped'=>(bool)$row['shipped'],'invoiced'=>(bool)$row['invoiced']];
                $invoiced=$value==='invoiced'?true:($value===''&&$before['rowStatus']==='invoiced'?false:$before['invoiced']);
                $status=$value;
                $after=['rowStatus'=>$status,'cancelled'=>$status==='cancelled','shipped'=>$status==='shipped','invoiced'=>$invoiced];
                $this->db->run('UPDATE shipment_entries SET row_status=?,cancelled=?,shipped=?,invoiced=?,version=version+1,updated=? WHERE id=? AND company_id=?',[$status,(int)$after['cancelled'],(int)$after['shipped'],(int)$invoiced,$now,$id,$company]);
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$user['id'],$company,'shipment.bulkStatus',$id,json_encode($before),json_encode($after),$now]);
                (new ShipmentChangeNotifications($this->db,$this->cipher))->record($user,$company,$id,'status',$before,$after);
                $confirmation=ShipmentFulfillment::confirm($this->db,$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key'),$user,$company,$id);
                if($status==='shipped'&&$before['rowStatus']!=='shipped')$dispatched[]=$row;
                $saved[]=['id'=>$id,...$after,...$confirmation,'version'=>$versions[$id]+1];
            }
            $savedRows=$this->db->all('SELECT * FROM shipment_entries WHERE '.$sql.' ORDER BY id',$params);
            foreach($projection->read($company,$savedRows) as $i=>[,, $status]){
                $saved[$i]=[...$saved[$i],...$status];
            }
            if($dispatched)(new ShipmentDispatchNotifications($this->db,$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key')))->record($user,$company,$dispatched);
            return ['items'=>$saved,'updated'=>count($saved)];
        });
    }
}

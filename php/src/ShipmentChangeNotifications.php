<?php
declare(strict_types=1);
namespace Omnigo;

/** Immutable, encrypted shipment events share the existing audit transaction. */
final class ShipmentChangeNotifications
{
    public function __construct(private Database $db,private ?DeliveryCipher $cipher=null){}
    private function cipher(): DeliveryCipher{return $this->cipher??=new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');}
    public function record(array $user,int $company,int $id,string $operation,array $before,array $after): void
    {
        $role=$user['role']??'';
        if($role!=='client'&&($role!=='admin'||$operation!=='document'))return;
        ShipmentAccess::company($user,$company);
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Bildirim kayıtla aynı işlem içinde oluşturulmalı.');
        $allowed=[...ShipmentRepository::FIELDS,...ShipmentRepository::OPTIONAL_FIELDS,'rowStatus','invoiced','invoiceDocument','deliveryDocument','recordState'];$changes=[];
        foreach($after as $field=>$value){
            $previous=$before[$field]??null;
            if($field==='packageStatus'||!in_array($field,$allowed,true)||$previous===$value||(in_array($previous,[null,''],true)&&in_array($value,[null,''],true)))continue;
            $changes[]=['field'=>$field,'before'=>$previous,'after'=>$value];
        }
        if(!$changes)return;
        $row=$this->db->one('SELECT provider,month,payload FROM shipment_entries WHERE id=? AND company_id=?',[$id,$company]);
        if(!$row)throw new \LogicException('Bildirim satırı bulunamadı.');
        $payload=$this->cipher()->open($company,'shipment:'.$id,$row['payload']);
        $actor=$this->db->one('SELECT name FROM users WHERE id=?',[$user['id']]);
        $event=['actorName'=>ClientSessionOperator::actorName($user,$actor['name']??null),'actorId'=>(int)$user['id'],'rowId'=>$id,'provider'=>$row['provider'],'month'=>$row['month'],'operation'=>$operation,'changes'=>$changes,
            'row'=>array_intersect_key($payload,array_flip(['orderedAt','customerName','recipientName','productName','province']))];
        $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(?,?,?,?,?,?)',[$user['id'],$company,'shipment.client_change',$id,'null',gmdate('c')]);
        $eventId=$this->db->insertId();
        $this->db->run('UPDATE audit SET after_value=? WHERE id=?',[$this->cipher()->seal($company,'shipment-notification:'.$eventId,$event),$eventId]);
        $summary=['operation'=>$operation,'audience'=>'admin','actorName'=>$event['actorName'],'fields'=>array_column($changes,'field')];
        if($operation==='document')$summary['documentSubject']=$this->db->one('SELECT name FROM companies WHERE id=?',[$company])['name']??'';
        (new OperationalNotifications($this->db))->publish($company,'shipment',$eventId,$row['provider'],$summary);
        if($role==='client'&&$operation==='document')foreach($changes as $change){
            if(!in_array($change['field'],['invoiceDocument','deliveryDocument'],true))continue;
            (new OperationalMailEvents($this->db,$this->cipher()))->record($company,$id,['events'=>['document_uploaded'],'provider'=>$row['provider'],'city'=>$payload['province']??'','actorName'=>$event['actorName'],'rowId'=>$id,'documentKind'=>$change['field']==='invoiceDocument'?'invoice':'delivery'],(int)$user['id']);
        }
    }
    public function detail(int $company,int $eventId): array
    {
        $row=$this->db->one("SELECT after_value,created FROM audit WHERE company_id=? AND id=? AND action='shipment.client_change'",[$company,$eventId]);
        if(!$row)Http::fail(404,'Sevkiyat bildirimi bulunamadı.');
        $event=$this->cipher()->open($company,'shipment-notification:'.$eventId,$row['after_value']);
        if($event['operation']==='document')$event['message']=ActivityNotification::shipment([
            'operation'=>'document','fields'=>array_column($event['changes'],'field'),'documentSubject'=>$event['row']['customerName']??''
        ]);
        return [...$event,'createdAt'=>$row['created']];
    }
    public function summary(int $company,int $eventId): array
    {
        $event=$this->detail($company,$eventId);
        return ['actorName'=>$event['actorName'],'rowId'=>$event['rowId'],'provider'=>$event['provider'],'operation'=>$event['operation'],'fields'=>array_column($event['changes'],'field'),...array_intersect_key($event,['message'=>true])];
    }
}

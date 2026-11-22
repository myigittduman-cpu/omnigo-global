<?php
declare(strict_types=1);
namespace Omnigo;

/** Durable encrypted events commit with the order or upload; SMTP runs only in the worker. */
final class OperationalMailEvents
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function record(int $company,int $record,array $detail,?int $actor=null): void
    {
        if(!$this->db->pdo->inTransaction())throw new \LogicException('E-posta olayı kayıtla aynı işlemde oluşturulmalı.');
        $detail=(new TransferNotificationGuard($this->db,$this->cipher))->filter($company,$record,$detail);
        if(empty($detail['events']))return;
        $detail=DeliveryNotificationName::enrich($this->db,$this->cipher,$company,$record,$detail);
        $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(?,?,?,?,?,?)',[$actor,$company,'operations.mail',$record,'null',gmdate('c')]);
        $id=$this->db->insertId();
        $this->db->run('UPDATE audit SET after_value=? WHERE id=?',[$this->cipher->seal($company,'operation-mail:'.$id,$detail),$id]);
        // New arrivals already have one order notification. Client documents use their change audit.
        $events=$detail['events']??[];
        if(ReconciliationPresentation::isEvent($detail))
            (new OperationalNotifications($this->db))->publish($company,'operation',$id,$detail['provider'],['events'=>$events,'audience'=>'admin']);
        elseif(array_intersect($events,['return_updated','dispatched'])||(!empty($detail['section'])&&array_intersect($events,['document_uploaded','document_deleted']))){
            $summary=array_intersect_key($detail,array_flip(['events','actorName','section','documentKind','city','orderCount','pieceCount','statusLabel','isNewReturn']));
            (new OperationalNotifications($this->db))->publish($company,'operation',$id,$detail['provider']??'portal',$summary);
        }
        elseif(array_intersect($events,['cancelled','cancellation_requested'])||(!in_array('new_order',$events,true)&&array_intersect($events,['delivery_added','shipment_added'])))
            (new OperationalNotifications($this->db))->publish($company,'operation',$id,$detail['provider']??'',['events'=>$events]);
    }
    public function enqueue(MailQueue $mail): int
    {
        (new ReturnNotificationObserver($this->db,$this->cipher))->run();
        if(!$mail->ready())return 0;
        return $this->db->transaction(function()use($mail){
            $keySql=$this->db->mysql()?"CONCAT('operation-',a.id)":"'operation-' || a.id";
            $rows=$this->db->all("SELECT a.* FROM audit a LEFT JOIN mail_outbox m ON m.id=$keySql WHERE a.action='operations.mail' AND m.id IS NULL ORDER BY a.id LIMIT 100".$this->db->lock());$count=0;
            foreach($rows as $row){
                $detail=$this->cipher->open((int)$row['company_id'],'operation-mail:'.$row['id'],$row['after_value']);
                $company=$this->db->one('SELECT name FROM companies WHERE id=?',[$row['company_id']]);
                if($mail->enqueueOperation('operation-'.$row['id'],[...$detail,'company'=>$company['name']??'','companyId'=>(int)$row['company_id'],'recordId'=>(int)$row['record_id'],'detectedAt'=>$row['created']]))$count++;
            }
            return $count+(new UserNotificationMail($this->db,$this->cipher))->enqueue($mail);
        });
    }
}

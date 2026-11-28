<?php
declare(strict_types=1);
namespace Omnigo;

/** Final bookkeeping confirmation. It never initiates a bank or marketplace payment. */
final class PaymentApproval
{
    public function __construct(private Database $db){}

    public static function isApproved(array $payment):bool
    {
        return !empty($payment['paymentApproval']['at']);
    }

    public static function afterCorrection(array $previous,array $payment,bool $confirmed):array
    {
        if(!self::isApproved($previous))return $payment;
        if(!$confirmed)Http::fail(409,'Bu ödeme onaylandı. Onaylı hakedişi düzenleme işlemini kullanın.');
        foreach(['channel','status','expectedDate','expectedCents','paidDate','actualCents','confirmation'] as $field){
            if(($previous[$field]??null)!==($payment[$field]??null))return $payment;
        }
        return [...$payment,'paymentApproval'=>$previous['paymentApproval']];
    }

    public function approve(array $actor,int $company,int $id,array $input):array
    {
        Security::admin($actor);
        ClientSessionOperator::requireName($actor);
        if(!is_int($input['version']??null)||$input['version']<1)Http::fail(400,'Hakediş sürümünü yenileyin.');
        $approve=function()use($actor,$company,$id,$input){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $row=$this->db->one("SELECT * FROM records WHERE company_id=? AND kind='payments' AND id=?".$this->db->lock(),[$company,$id]);
            if(!$row)Http::fail(404,'Hakediş kaydı bulunamadı.');
            $payment=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
            // A repeated request cannot produce a second audit entry or change the receipt.
            if(self::isApproved($payment))return [...$payment,'id'=>$id,'version'=>(int)$row['version'],'updated'=>$row['updated']];
            if((int)$row['version']!==$input['version'])Http::fail(409,'Hakediş değişti. Güncel kaydı kontrol edip tekrar deneyin.');
            if(!empty($payment['integration'])||!empty($payment['archivedAt']))Http::fail(409,'Arşivdeki hakediş onaylanamaz.');
            if(($payment['status']??'')!=='paid'||!is_int($payment['actualCents']??null)||empty($payment['paidDate']))Http::fail(409,'Önce hesaba geçen tutarı ve tarihi kaydedin.');
            $updated=gmdate('c');
            $payment['paymentApproval']=['at'=>$updated,'userId'=>(int)$actor['id']];
            $payload=json_encode($payment,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $this->db->run("UPDATE records SET payload=?,updated=?,version=version+1 WHERE company_id=? AND kind='payments' AND id=? AND version=?",[$payload,$updated,$company,$id,$input['version']]);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$actor['id'],$company,'Ödeme onaylandı',$id,$row['payload'],$payload,$updated]);
            CustomerServiceNotifications::paymentApproved($this->db,$company,$id,$this->db->insertId(),$payment);
            return [...$payment,'id'=>$id,'version'=>(int)$row['version']+1,'updated'=>$updated];
        };
        return $this->db->pdo->inTransaction()?$approve():$this->db->transaction($approve);
    }
}

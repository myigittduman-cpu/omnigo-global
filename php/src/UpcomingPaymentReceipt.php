<?php
declare(strict_types=1);
namespace Omnigo;

/** Convert one displayed forecast into an approved receipt without changing marketplace imports. */
final class UpcomingPaymentReceipt
{
    public function __construct(private Database $db) {}

    public function approve(array $actor,int $company,array $input): array
    {
        Security::admin($actor);ClientSessionOperator::requireName($actor);
        $source=$input['id']??null;
        if(!is_int($source)&&!is_string($source)||is_string($source)&&strlen($source)>120||$source==='')Http::fail(400,'Hakediş seçimini kontrol edin.');
        $paidDate=Validation::date($input['paidDate']??null);$actualCents=Validation::integer($input['actualCents']??null);
        $sourceKey=hash('sha256',(string)$source);
        return TransactionRetry::run($this->db,function()use($actor,$company,$input,$source,$sourceKey,$paidDate,$actualCents){
            if(!$this->db->mysql())$this->db->run('UPDATE records SET version=version WHERE 1=0');
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))Http::fail(404,'Mağaza bulunamadı.');
            $repo=new Repository($this->db);$payments=$repo->records($company,'payments',$actor);
            foreach($payments as $payment)if(($payment['upcomingReceiptSource']['key']??null)===$sourceKey){
                if(PaymentApproval::isApproved($payment)&&$payment['actualCents']===$actualCents&&$payment['paidDate']===$paidDate)return $payment;
                Http::fail(409,'Bu hakediş için ödeme kaydı zaten var. Ödeme detaylarından kontrol edin.');
            }
            $today=(new \DateTimeImmutable('today',new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
            $forecast=(new PaymentCalendar($this->db))->report($actor,$company,$today,$today,'all',true)['upcoming'];
            $item=null;foreach($forecast['periodItems'] as $candidate)if($candidate['id']===$source){$item=$candidate;break;}
            if(!$item||!empty($item['paymentApproval']))Http::fail(409,'Hakediş değişti. Listeyi yenileyip tekrar deneyin.');
            $existing=null;
            if($item['source']==='manual'){
                foreach($payments as $payment)if($payment['id']===$source){$existing=$payment;break;}
                if(!$existing||($input['version']??null)!==$existing['version']||$existing['status']!=='pending')Http::fail(409,'Hakediş sürümü değişti. Listeyi yenileyin.');
                $value=$existing;$id=$existing['id'];$version=$existing['version'];
                unset($value['id'],$value['version'],$value['updated'],$value['legacyManual'],$value['excludedFromTotals']);
            }else{
                if(!is_string($input['revision']??null)||!hash_equals($item['receiptRevision']??'',$input['revision'])||$input['revision']==='')Http::fail(409,'Hakediş tutarı değişti. Listeyi yenileyin.');
                $value=Validation::record('payments',['channel'=>$item['channel'],'expectedDate'=>$item['date'],'expectedCents'=>$item['amountCents'],'status'=>'pending','source'=>'Manuel hakediş onayı','verification'=>'estimated']);
                $id=null;$version=1;
            }
            $value=[...$value,'status'=>'paid','actualCents'=>$actualCents,'paidDate'=>$paidDate,'confirmation'=>'Yönetici tarafından manuel onaylandı',
                'upcomingReceiptSource'=>['key'=>$sourceKey,'id'=>$source,'date'=>$item['date']]];
            $updated=gmdate('c');$payload=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if($id!==null){$this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=? AND company_id=? AND version=?',[$payload,$updated,$id,$company,$version]);$version++;}
            else{$this->db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'payments',?,?)",[$company,$payload,$updated]);$id=$this->db->insertId();}
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$actor['id'],$company,'Hakediş hesaba geçti olarak manuel kaydedildi',$id,$existing?json_encode($existing,JSON_THROW_ON_ERROR):null,$payload,$updated]);
            return (new PaymentApproval($this->db))->approve($actor,$company,$id,['version'=>$version]);
        });
    }

    public static function project(array $items,array $payments,array $allowed): array
    {
        foreach($payments as $payment){
            $source=$payment['upcomingReceiptSource']??null;
            if(!PaymentApproval::isApproved($payment)||$payment['status']!=='paid'||!in_array($payment['channel'],$allowed,true)
                ||!empty($payment['integration'])||!empty($payment['archivedAt'])||!empty($payment['paymentStateUnknown'])||!empty($payment['excludedFromTotals']))continue;
            $items=array_values(array_filter($items,static fn($item)=>PaymentApproval::isApproved($item)||($source
                ? (string)$item['id']!==(string)$source['id']
                : !($item['channel']===$payment['channel']&&$item['date']===$payment['expectedDate']))));
            $items[]=['id'=>'receipt:'.$payment['id'],'recordId'=>$payment['id'],'channel'=>$payment['channel'],'date'=>$payment['expectedDate']?:$payment['paidDate'],
                'amountCents'=>$payment['actualCents'],'source'=>'received','verification'=>'confirmed','paidDate'=>$payment['paidDate'],'paymentApproval'=>$payment['paymentApproval']];
        }
        usort($items,static fn($a,$b)=>[$a['date'],$a['channel'],(string)$a['id']]<=>[$b['date'],$b['channel'],(string)$b['id']]);
        return $items;
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit invoice-only maintenance for legacy Excel rows without an order key. */
final class ShipmentLegacyInvoiceAlignment
{
    public function __construct(private Database $db,private DeliveryCipher $cipher,private array $referenceOrders=[],private array $referenceDeliveries=[]){}
    private static function normal(string $value): string
    {
        return preg_replace('/[^\pL\pN]/u','',mb_strtolower(strtr($value,['İ'=>'i','I'=>'i','ı'=>'i'])));
    }
    public static function matches(array $saved,array $delivery,array $line): bool
    {
        try{
            if(empty($saved['orderedAt'])||empty($delivery['createdAt']))return false;
            $zone=new \DateTimeZone('Europe/Istanbul');
            $a=(new \DateTimeImmutable($saved['orderedAt'],$zone))->getTimestamp();$b=(new \DateTimeImmutable($delivery['createdAt'],$zone))->getTimestamp();
        }catch(\Throwable){return false;}
        if(!$a||!$b)return false;
        // Older n11 sheets use the order time; the package timestamp can follow shortly after it.
        // All other identity checks and one-to-one uniqueness still remain mandatory.
        if(intdiv($a,60)!==intdiv($b,60)&&!(($delivery['provider']??'')==='n11'&&abs($a-$b)<=180))return false;
        $phone=static fn($s)=>substr(preg_replace('/\D/','',(string)$s),-10);
        if(strlen($phone($saved['phone']??''))!==10||$phone($saved['phone'])!==$phone($delivery['phone']??''))return false;
        $buyer=self::normal($saved['customerName']??'');
        $recipient=self::normal($saved['recipientName']??'');
        $sameBuyer=$buyer!==''&&$buyer===self::normal($delivery['buyerName']??'');
        // Account holder and delivery recipient may differ. The explicitly recorded recipient
        // is also an identity, with the same phone, time, city, product and uniqueness guards.
        $sameRecipient=mb_strlen($recipient)>=5&&$recipient===self::normal($delivery['details']['recipientName']??'');
        if(!$sameBuyer&&!$sameRecipient)return false;
        $city=self::normal($saved['province']??'');
        if($city===''||$city!==self::normal($delivery['city']??''))return false;
        if(($saved['quantity']??0)!==($line['quantity']??-1)||$saved['quantity']<1)return false;
        $title=self::normal($saved['productName']??'');$product=self::normal($line['title']??'');$sku=self::normal($line['sku']??'');
        return (mb_strlen($title)>=24&&$title===$product)||(strlen($sku)>=5&&str_contains($title,$sku));
    }
    public function plan(int $company): array
    {
        $canonical=(new ShipmentInvoiceAlignment($this->db,$this->cipher,$this->referenceOrders,$this->referenceDeliveries))->verifiedSources($company);
        $repo=new DeliveryRepository($this->db,$this->cipher);$lines=[];$manual=[];
        $references=array_map(fn($d)=>['provider'=>$d['provider'],'external_id'=>$d['externalId'],'decoded'=>$d],$this->referenceDeliveries);
        foreach([...$this->db->all('SELECT * FROM delivery_orders WHERE company_id=? ORDER BY id',[$company]),...$references] as $row){
            if(!in_array($row['provider'],['Hepsiburada','n11','Pazarama','PTT AVM','Koçtaş'],true))continue;
            $d=$row['decoded']??$repo->decode($row);$identity=$row['provider']==='Pazarama'?$row['external_id']:($d['orderNumber']??'');if($identity==='')continue;
            foreach($d['details']['products']??[] as $line){
                if(empty($line['lineId']))continue;
                $key=ShipmentInvoiceAlignment::sourceKey($row['provider'],$identity,(string)$line['lineId']);
                if(!isset($canonical[$key]))continue;
                if($this->db->one('SELECT id FROM shipment_entries WHERE company_id=? AND source_key=?',[$company,$key]))continue;
                $lines[$row['provider']][]=['key'=>$key,'delivery'=>$d,'line'=>$line];
            }
        }
        foreach($this->db->all("SELECT record_id,after_value FROM audit WHERE company_id=? AND action='shipment.edit'",[$company]) as $row){
            if(in_array('invoiceCents',json_decode($row['after_value'],true)['fields']??[],true))$manual[(int)$row['record_id']]=true;
        }
        $pending=[];$uses=[];$result=['items'=>[],'matched'=>[],'unmatched'=>[],'blocked'=>[]];
        foreach($this->db->all('SELECT * FROM shipment_entries WHERE company_id=? AND source_key IS NULL AND removed_at IS NULL ORDER BY id',[$company]) as $row){
            if(!in_array($row['provider'],['Hepsiburada','n11','Pazarama','PTT AVM','Koçtaş'],true))continue;
            $id=(int)$row['id'];$saved=$this->cipher->open($company,'shipment:'.$id,$row['payload']);$matches=[];
            if($row['provider']==='Pazarama'){
                $result['blocked'][]=['id'=>$id,'provider'=>$row['provider'],'reason'=>'unlinked_invoice'];continue;
            }
            foreach($lines[$row['provider']]??[] as $candidate)if(self::matches($saved,$candidate['delivery'],$candidate['line']))$matches[$candidate['key']]=$candidate;
            if(count($matches)!==1){$result[$matches?'blocked':'unmatched'][]=['id'=>$id,'provider'=>$row['provider'],'month'=>$row['month'],'reason'=>$matches?'ambiguous':'no_exact_source'];continue;}
            $candidate=array_values($matches)[0];$key=$candidate['key'];$uses[$key]=($uses[$key]??0)+1;
            $pending[]=['id'=>$id,'provider'=>$row['provider'],'sourceKey'=>$key,'orderNumber'=>$candidate['delivery']['orderNumber'],
                'lineId'=>(string)$candidate['line']['lineId'],'version'=>(int)$row['version'],'before'=>$saved['invoiceCents']??null,
                'after'=>$canonical[$key]['amount'],'quantity'=>$canonical[$key]['quantity']];
        }
        foreach($pending as $item){
            if(isset($manual[$item['id']])||$uses[$item['sourceKey']]!==1){$result['blocked'][]=['id'=>$item['id'],'provider'=>$item['provider'],'reason'=>isset($manual[$item['id']])?'manual_invoice':'duplicate_legacy'];continue;}
            $result['matched'][$item['provider']]=($result['matched'][$item['provider']]??0)+1;
            if($item['before']!==$item['after'])$result['items'][]=$item;
        }
        return $result;
    }
    public function apply(int $company,array $approved,string $backupPath): array
    {
        return $this->db->transaction(function()use($company,$approved,$backupPath){
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))throw new \RuntimeException('Firma bulunamadı.');
            if($this->plan($company)['items']!==$approved)throw new \RuntimeException('İncelenen plan değişti; yeniden doğrulayın.');
            $rows=[];foreach($approved as $item)$rows[]=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND id=?'.$this->db->lock(),[$company,$item['id']]);
            $backup=json_encode(['company'=>$company,'created'=>gmdate('c'),'rows'=>$rows],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $handle=fopen($backupPath,'x');if(!$handle)throw new \RuntimeException('Yedek oluşturulamadı.');
            try{if(!chmod($backupPath,0600)||fwrite($handle,$backup)!==strlen($backup)||!fflush($handle))throw new \RuntimeException('Yedek doğrulanamadı.');}finally{fclose($handle);}
            foreach($approved as $index=>$item){
                $row=$rows[$index];$id=$item['id'];$value=$this->cipher->open($company,'shipment:'.$id,$row['payload']);$value['invoiceCents']=$item['after'];
                $written=$this->db->run('UPDATE shipment_entries SET payload=?,version=version+1,updated=? WHERE company_id=? AND id=? AND version=? AND source_key IS NULL AND removed_at IS NULL',
                    [$this->cipher->seal($company,'shipment:'.$id,$value),gmdate('c'),$company,$id,$item['version']]);
                if($written->rowCount()!==1)throw new \RuntimeException('Satır eşzamanlı değişti; işlem geri alındı.');
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,?,?,?)',
                    [$company,'shipment.legacy_invoice_reconcile',$id,json_encode(['invoiceCents'=>$item['before']]),json_encode(['invoiceCents'=>$item['after'],'sourceKey'=>$item['sourceKey'],'orderNumber'=>$item['orderNumber'],'lineId'=>$item['lineId'],'fields'=>['invoiceCents']]),gmdate('c')]);
            }
            if($this->plan($company)['items'])throw new \RuntimeException('Düzeltme sonrası tutarlar uyuşmuyor.');
            return ['amountsChanged'=>count($rows),'backupSha256'=>hash('sha256',$backup)];
        });
    }
}

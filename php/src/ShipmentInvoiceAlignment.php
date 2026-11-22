<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit maintenance operation; never guesses identities or overrides manual price edits. */
final class ShipmentInvoiceAlignment
{
    private const PROVIDERS=['Hepsiburada'=>'hepsiburada','n11'=>'n11','Pazarama'=>'pazarama','PTT AVM'=>'pttavm','Koçtaş'=>'koctas'];
    public function __construct(private Database $db,private DeliveryCipher $cipher,private array $verifiedReferences=[],private array $referenceDeliveries=[]){}
    public static function sourceKey(string $provider,string $order,string $line): string
    {
        return hash('sha256',$provider.':'.hash('sha256',json_encode([$order,$line],JSON_THROW_ON_ERROR)));
    }
    /** Canonical line totals confirmed independently by order and delivery records. */
    public function verifiedSources(int $company): array
    {
        $orders=[];
        $financial=$this->db->all('SELECT kind AS provider,payload FROM records WHERE company_id=? AND kind LIKE ? ORDER BY id',[$company,'order_%']);
        foreach($financial as &$row)$row['provider']=substr($row['provider'],6);unset($row);
        foreach([...$this->db->all('SELECT provider,payload FROM recent_orders WHERE company_id=? ORDER BY id',[$company]),...$financial,...$this->verifiedReferences] as $row){
            if(!in_array($row['provider'],self::PROVIDERS,true))continue;
            $order=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            if(($order['orders']??0)!==1)continue;
            $key=$row['provider'].':'.($order['externalId']??'');
            $entries=[];
            foreach($order['products']??[] as $line){
                $id=(string)($line['lineId']??'');
                $token=$id!==''?'line:'.$id:'sku:'.($line['sku']??'');
                if($token==='sku:')continue;
                $value=['quantity'=>$line['quantity']??null,'amount'=>$line['grossCents']??null];
                if($row['provider']==='pazarama'){
                    if($id==='')continue;
                    $value['sellerTotal']=$value['amount'];
                    $value['amount']=PazaramaInvoiceAmount::fromPricing($line['pricing']??null,$value['sellerTotal']);
                }
                $entries[$token]=array_key_exists($token,$entries)?null:$value;
            }
            foreach($entries as $token=>$value){
                if(array_key_exists($token,$orders[$key]??[])&&$orders[$key][$token]!==$value)$orders[$key][$token]=null;
                else $orders[$key][$token]=$value;
            }
        }
        $sources=[];$repo=new DeliveryRepository($this->db,$this->cipher);
        $references=array_map(fn($d)=>['provider'=>$d['provider'],'external_id'=>$d['externalId'],'decoded'=>$d],$this->referenceDeliveries);
        foreach([...$this->db->all('SELECT * FROM delivery_orders WHERE company_id=? ORDER BY id',[$company]),...$references] as $row){
            $provider=$row['provider'];if(!isset(self::PROVIDERS[$provider]))continue;
            $delivery=$row['decoded']??$repo->decode($row);
            $identity=$provider==='Pazarama'?$row['external_id']:($delivery['orderNumber']??'');
            if($identity==='')continue;
            foreach($delivery['details']['products']??[] as $line){
                $id=(string)($line['lineId']??'');if($id==='')continue;
                $value=['quantity'=>$line['quantity']??null,'amount'=>$line['amountCents']??null];
                $financialLines=$orders[self::PROVIDERS[$provider].':'.$identity]??[];
                $canonical=array_key_exists('line:'.$id,$financialLines)?$financialLines['line:'.$id]:($financialLines['sku:'.($line['sku']??'')]??null);
                $key=self::sourceKey($provider,$identity,$id);
                if($provider==='Pazarama'&&$canonical!==null){
                    // Older delivery details recorded the seller total. New details also identify the buyer invoice explicitly.
                    $matches=array_key_exists('invoiceCents',$line)?$line['invoiceCents']===$canonical['amount']&&$value['amount']===$canonical['amount']:
                        $value['amount']===$canonical['sellerTotal'];
                    $value['amount']=$matches?$canonical['amount']:null;
                    unset($canonical['sellerTotal']);
                }
                $valid=$canonical===$value&&is_int($value['amount'])&&$value['amount']>=0&&is_int($value['quantity'])&&$value['quantity']>0;
                if(!$valid||(array_key_exists($key,$sources)&&$sources[$key]!==$value))$sources[$key]=null;
                else $sources[$key]=$value;
            }
        }
        return $sources;
    }
    public function plan(int $company): array
    {
        $sources=$this->verifiedSources($company);$manual=[];
        foreach($this->db->all("SELECT record_id,after_value FROM audit WHERE company_id=? AND action='shipment.edit'",[$company]) as $row){
            $details=json_decode($row['after_value']??'null',true);
            if(in_array('invoiceCents',$details['fields']??[],true))$manual[(int)$row['record_id']]=true;
        }
        $result=['items'=>[],'matched'=>[],'unlinked'=>[],'blocked'=>[]];
        foreach($this->db->all('SELECT * FROM shipment_entries WHERE company_id=? AND removed_at IS NULL ORDER BY id',[$company]) as $row){
            $provider=$row['provider'];if(!isset(self::PROVIDERS[$provider]))continue;
            $id=(int)$row['id'];$source=$row['source_key'];
            if(!$source){$result['unlinked'][]=['id'=>$id,'provider'=>$provider,'month'=>$row['month']];continue;}
            $value=$this->cipher->open($company,'shipment:'.$id,$row['payload']);$line=$sources[$source]??null;
            $reason=isset($manual[$id])?'manual_invoice':null;
            if($provider==='Pazarama'&&PazaramaShipmentInvoice::protectedRow($row,$value))$reason='protected_invoice';
            if(!$line||$line['quantity']!==($value['quantity']??null))$reason='source_conflict';
            if(($value['source']['kind']??'')!=='marketplace'||($value['source']['provider']??'')!==$provider)$reason='source_identity';
            if($reason){$result['blocked'][]=['id'=>$id,'provider'=>$provider,'reason'=>$reason];continue;}
            $result['matched'][$provider]=($result['matched'][$provider]??0)+1;
            $locked=in_array('invoiceCents',$value['source']['locked']??[],true);
            if(($value['invoiceCents']??null)===$line['amount']&&!$locked)continue;
            $result['items'][]=['id'=>$id,'provider'=>$provider,'sourceKey'=>$source,'version'=>(int)$row['version'],
                'before'=>$value['invoiceCents']??null,'after'=>$line['amount'],'unlock'=>$locked];
        }
        return $result;
    }
    public function apply(int $company,array $approved,string $backupPath): array
    {
        return $this->db->transaction(function()use($company,$approved,$backupPath){
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))throw new \RuntimeException('Firma bulunamadı.');
            $current=$this->plan($company);
            if($current['items']!==$approved)throw new \RuntimeException('Kayıt veya kaynak değişti; düzeltme planını yeniden doğrulayın.');
            $rows=[];foreach($approved as $item)$rows[]=$this->db->one('SELECT * FROM shipment_entries WHERE company_id=? AND id=?'.$this->db->lock(),[$company,$item['id']]);
            $backup=json_encode(['company'=>$company,'created'=>gmdate('c'),'rows'=>$rows],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $handle=fopen($backupPath,'x');if(!$handle)throw new \RuntimeException('Yedek oluşturulamadı.');
            try{if(!chmod($backupPath,0600)||fwrite($handle,$backup)!==strlen($backup)||!fflush($handle))throw new \RuntimeException('Yedek doğrulanamadı.');}finally{fclose($handle);}
            $changed=0;$unlocked=0;
            foreach($approved as $index=>$item){
                $row=$rows[$index];$id=$item['id'];$value=$this->cipher->open($company,'shipment:'.$id,$row['payload']);
                $value['invoiceCents']=$item['after'];
                if($item['unlock'])$value['source']['locked']=array_values(array_diff($value['source']['locked'],['invoiceCents']));
                $written=$this->db->run('UPDATE shipment_entries SET payload=?,version=version+1,updated=? WHERE company_id=? AND id=? AND version=? AND removed_at IS NULL',
                    [$this->cipher->seal($company,'shipment:'.$id,$value),gmdate('c'),$company,$id,$item['version']]);
                if($written->rowCount()!==1)throw new \RuntimeException('Kayıt eşzamanlı değişti; işlem geri alındı.');
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,?,?,?)',
                    [$company,'shipment.invoice_reconcile',$id,json_encode(['invoiceCents'=>$item['before']]),json_encode(['invoiceCents'=>$item['after'],'sourceKey'=>$item['sourceKey'],'invoiceLockRemoved'=>$item['unlock'],'version'=>$item['version']+1]),gmdate('c')]);
                $changed+=(int)($item['before']!==$item['after']);$unlocked+=(int)$item['unlock'];
            }
            if($this->plan($company)['items'])throw new \RuntimeException('Düzeltme sonrası tutar doğrulanamadı; işlem geri alındı.');
            return ['amountsChanged'=>$changed,'invoiceLocksRemoved'=>$unlocked,'rows'=>count($rows),'backupSha256'=>hash('sha256',$backup)];
        });
    }
}

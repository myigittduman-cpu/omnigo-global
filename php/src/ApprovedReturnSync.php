<?php
declare(strict_types=1);
namespace Omnigo;

/** Local, resumable projection in the existing provider worker; no marketplace writes. */
final class ApprovedReturnSync
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function batch(int $company,string $provider,int $cursor=0,int $limit=100): array
    {
        $limit=max(1,min(100,$limit));
        return TransactionRetry::run($this->db,function()use($company,$provider,$cursor,$limit){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $index=ApprovedReturnIndex::snapshot($this->db,$company,$provider);$changed=0;$issues=0;
            $rows=$this->db->all('SELECT * FROM delivery_orders WHERE company_id=? AND provider=? AND id>? ORDER BY id LIMIT '.$limit.$this->db->lock(),[$company,$provider,$cursor]);
            $orders=[];$keys=[];$entries=[];
            foreach($rows as $row){
                $order=$this->cipher->open($company,'order:'.$provider.':'.$row['external_id'],$row['payload']);$orders[$row['id']]=$order;
                if(!empty($order['details']['superseded']))continue;
                foreach($order['details']['products']??[] as $product){$key=DeliveryFulfillment::key($order,$product);if($key!==null)$keys[$key]=true;}
            }
            foreach(array_chunk(array_keys($keys),200) as $batch){
                foreach($this->db->all('SELECT * FROM shipment_entries WHERE company_id=? AND source_key IN ('.implode(',',array_fill(0,count($batch),'?')).')'.$this->db->lock(),[$company,...$batch]) as $entry)$entries[$entry['source_key']]=$entry;
            }
            $processed=[];
            foreach($rows as $row){
                $cursor=(int)$row['id'];$order=$orders[$row['id']];
                if(!empty($order['details']['superseded']))continue;
                $projection=$index->lines($order);$issues+=count($projection['issues']);$orderChanged=false;
                foreach($order['details']['products']??[] as $product){
                    $key=DeliveryFulfillment::key($order,$product);if($key===null)continue;
                    $entry=$entries[$key]??null;
                    if(!$entry||$entry['removed_at']!==null)continue;
                    // Multiple packages may carry the same line. Apply it once per pass.
                    if(isset($processed[$key]))continue;$processed[$key]=true;
                    $payload=$this->cipher->open($company,'shipment:'.$entry['id'],$entry['payload']);
                    $next=$projection['lines'][(string)$product['lineId']]??null;
                    if(($payload['marketplaceReturn']??null)===$next)continue;
                    // Failed/missing quantity matching cannot revive a previously verified return.
                    if($next===null&&$projection['issues'])continue;
                    if($next===null)unset($payload['marketplaceReturn']);else $payload['marketplaceReturn']=$next;
                    $this->db->run('UPDATE shipment_entries SET payload=?,version=version+1,updated=? WHERE company_id=? AND id=?',[$this->cipher->seal($company,'shipment:'.$entry['id'],$payload),gmdate('c'),$company,$entry['id']]);
                    $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,after_value,created) VALUES(NULL,?,?,?,?,?)',[$company,'shipment.approved_return',$entry['id'],json_encode($next,JSON_THROW_ON_ERROR),gmdate('c')]);
                    $changed++;$orderChanged=true;
                }
                if($orderChanged)$this->db->run('UPDATE delivery_orders SET version=version+1,document_id=NULL,updated=? WHERE company_id=? AND id=?',[gmdate('c'),$company,$row['id']]);
            }
            return ['cursor'=>count($rows)===$limit?$cursor:0,'changed'=>$changed,'issues'=>$issues,'scanned'=>count($rows)];
        });
    }
    public function run(int $company,string $provider,string $storage,int $deadline): void
    {
        // Provider worker already holds its exclusive process lock. Cursor files contain no PII.
        $key=array_search($provider,ReturnRequestList::PROVIDERS,true);if($key===false)return;
        $path=$storage.'/approved-returns-'.$key.'-'.$company.'.json';
        $previous=is_file($path)?json_decode(file_get_contents($path),true):[];$cursor=(int)($previous['cursor']??0);
        $changed=0;$issues=0;$scanned=0;
        do{
            if(time()>=$deadline)break;
            $result=$this->batch($company,$provider,$cursor);$cursor=$result['cursor'];$changed+=$result['changed'];$issues+=$result['issues'];$scanned+=$result['scanned'];
            file_put_contents($path,json_encode(['cursor'=>$cursor,'at'=>gmdate('c'),'changed'=>$changed,'issues'=>$issues,'scanned'=>$scanned],JSON_THROW_ON_ERROR),LOCK_EX);
        }while($cursor>0);
    }
}

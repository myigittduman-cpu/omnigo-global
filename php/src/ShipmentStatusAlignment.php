<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit maintenance action: restore source status without touching manually corrected order fields. */
final class ShipmentStatusAlignment
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function run(int $company,callable $backup): array
    {
        return TransactionRetry::run($this->db,function()use($company,$backup){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $cursor=0;$updated=[];$unknown=[];$manual=0;
            do{
                $rows=$this->db->all('SELECT * FROM shipment_entries WHERE company_id=? AND id>? AND removed_at IS NULL ORDER BY id LIMIT 100'.$this->db->lock(),[$company,$cursor]);
                foreach($rows as $row){
                    $cursor=(int)$row['id'];if(!$row['source_key']){$manual++;continue;}
                    $payload=$this->cipher->open($company,'shipment:'.$cursor,$row['payload']);$status=ShipmentSourceStatus::rowStatus($payload);
                    if($status===null){$unknown[]=$cursor;continue;}
                    if(empty($payload['manualStatusOverride']['enabled'])&&$status===ShipmentStatus::resolve($row))continue;
                    $backup($row);$before=['rowStatus'=>ShipmentStatus::resolve($row),'override'=>!empty($payload['manualStatusOverride']['enabled'])];
                    unset($payload['manualStatusOverride']);
                    $this->db->run('UPDATE shipment_entries SET row_status=?,shipped=?,cancelled=?,payload=?,version=version+1,updated=? WHERE company_id=? AND id=?',
                        [$status,(int)($status==='shipped'),(int)($status==='cancelled'),$this->cipher->seal($company,'shipment:'.$cursor,$payload),gmdate('c'),$company,$cursor]);
                    ShipmentFulfillment::confirm($this->db,$this->cipher,[], $company,$cursor,false);
                    $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,?,?,?)',
                        [$company,'shipment.source_alignment',$cursor,json_encode($before),json_encode(['rowStatus'=>$status,'override'=>false]),gmdate('c')]);
                    $updated[]=['id'=>$cursor,'provider'=>$row['provider'],'from'=>$before['rowStatus'],'to'=>$status];
                }
            }while(count($rows)===100);
            return ['updated'=>$updated,'unknown'=>$unknown,'manualRowsPreserved'=>$manual];
        });
    }
}

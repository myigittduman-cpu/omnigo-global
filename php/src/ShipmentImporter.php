<?php
declare(strict_types=1);
namespace Omnigo;

/** Called by an authenticated maintenance job, never exposed as a public upload endpoint. */
final class ShipmentImporter
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function import(int $company,array $items,string $sourceHash): array
    {
        if(!$items||count($items)>2000||!preg_match('/^[a-f0-9]{64}$/D',$sourceHash))throw new \InvalidArgumentException('Aktarım paketi geçersiz.');
        foreach($items as $item){ShipmentRepository::selection($item);if(array_keys(array_diff_key($item['payload'],array_flip(ShipmentRepository::OPTIONAL_FIELDS)))!==ShipmentRepository::FIELDS)throw new \InvalidArgumentException('Alan eşleşmesi geçersiz.');}
        return $this->db->transaction(function() use($company,$items,$sourceHash){
            if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]))throw new \RuntimeException('Firma bulunamadı.');
            $existing=[];$occurrences=[];$added=[];$skipped=0;$counts=[];$scopes=[];
            foreach($items as $item)$scopes[json_encode([$item['city'],$item['provider'],$item['month']])]=[$item['city'],$item['provider'],$item['month']];
            foreach($scopes as $scope){
                foreach($this->db->all('SELECT id,city,provider,month,payload FROM shipment_entries WHERE company_id=? AND city=? AND provider=? AND month=?',[$company,...$scope]) as $row){
                    $payload=$this->cipher->open($company,'shipment:'.$row['id'],$row['payload']);
                    $key=self::fingerprint($row,$payload);$existing[$key]=($existing[$key]??0)+1;
                }
            }
            foreach($items as $item){
                $key=self::fingerprint($item,$item['payload']);$occurrences[$key]=($occurrences[$key]??0)+1;
                if($occurrences[$key]<=($existing[$key]??0)){$skipped++;continue;}
                $now=gmdate('c');
                $this->db->run('INSERT INTO shipment_entries(company_id,city,provider,month,payload,created,updated,ordered_at) VALUES(?,?,?,?,?,?,?,?)',[$company,$item['city'],$item['provider'],$item['month'],'',$now,$now,ShipmentOrdering::timestamp($item['payload'])]);
                $id=$this->db->insertId();$payload=[...$item['payload'],'import'=>['sourceHash'=>$sourceHash,'sourceRow'=>$item['sourceRow']]];
                $this->db->run('UPDATE shipment_entries SET payload=? WHERE id=?',[$this->cipher->seal($company,'shipment:'.$id,$payload),$id]);
                $added[]=$id;$counts[$item['city']]=($counts[$item['city']]??0)+1;
            }
            if($added)$this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,NULL,NULL,?,?)',[$company,'shipment.import',json_encode(['sourceHash'=>$sourceHash,'added'=>count($added),'skipped'=>$skipped,'ids'=>$added]),gmdate('c')]);
            return ['added'=>count($added),'skipped'=>$skipped,'byCity'=>$counts,'ids'=>$added];
        });
    }
    private static function fingerprint(array $row,array $payload): string
    {
        $fields=[];foreach(ShipmentRepository::FIELDS as $key)$fields[$key]=$payload[$key]??null;
        foreach(ShipmentRepository::OPTIONAL_FIELDS as $key)if(($payload[$key]??'')!=='')$fields[$key]=$payload[$key];
        return hash('sha256',json_encode([$row['city'],$row['provider'],$row['month'],$fields],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
}

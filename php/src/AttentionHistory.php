<?php
declare(strict_types=1);
namespace Omnigo;

/** Only current source suppression markers are retained; no completed-case archive. */
final class AttentionHistory
{
    public function __construct(private Database $db,private AttentionSources $sources){}
    public function reconcile(array $user,int $company,array $items,string $type):array
    {
        $live=array_column($items,null,'key');
        foreach($this->db->all('SELECT * FROM operation_attention_sources WHERE company_id=?',[$company]) as $row){
            $item=$this->sources->open($company,$row);
            if($type!=='all'&&$item['type']!==$type)continue;
            $saved=$this->db->one('SELECT * FROM operation_attention WHERE company_id=? AND issue_key=?',[$company,$item['key']]);
            $changed=isset($live[$row['issue_key']])&&!hash_equals($row['fingerprint'],$live[$row['issue_key']]['fingerprint']);
            $cleared=!isset($live[$row['issue_key']])&&$this->sources->resolved($user,$company,$item);
            if(!$saved||$saved['status']!=='resolved'||$changed||$cleared){
                $this->db->run('DELETE FROM operation_attention_sources WHERE company_id=? AND issue_key=? AND fingerprint=?',[$company,$row['issue_key'],$row['fingerprint']]);
                if($saved)$this->db->run('DELETE FROM operation_attention WHERE company_id=? AND issue_key=? AND version=? AND fingerprint=?',[$company,$row['issue_key'],(int)$saved['version'],$saved['fingerprint']]);
            }
        }
        return $items;
    }
    public function suppress(int $company,array $item):void
    {
        $this->db->run('DELETE FROM operation_attention_sources WHERE company_id=? AND issue_key=?',[$company,$item['key']]);
        $this->db->run('INSERT INTO operation_attention_sources(company_id,issue_key,payload,fingerprint,updated) VALUES(?,?,?,?,?)',[$company,$item['key'],$this->sources->seal($company,$item),$item['fingerprint'],gmdate('c')]);
    }
}

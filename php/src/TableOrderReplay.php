<?php
declare(strict_types=1);
namespace Omnigo;

/** Reopen only a list entry matched by an order line returned in this full read. */
final class TableOrderReplay
{
    public static function restore(Database $db,int $company,string $table,string $provider,array $item,int $requestedAt,?DeliveryCipher $cipher=null): int
    {
        if(!in_array($table,['shipments','shipmentInvoices'],true))return 0;
        if(!$db->pdo->inTransaction())throw new \LogicException('Satır geri alımı kaynak aktarımıyla aynı işlemde yapılmalı.');
        $channel=CustomerMarketplaces::PROVIDERS[$provider]??null;
        if(!$channel||$requestedAt<=0||empty($item['order']['externalId']))return 0;
        $keys=[];
        foreach($item['deliveries']??[] as $delivery){
            foreach($delivery['shipmentItems']??[] as $line){
                $key=$line['key']??null;
                if(is_string($key)&&trim($key)!=='')$keys[hash('sha256',$channel.':'.$key)]=true;
            }
        }
        if(!$keys)return 0;
        $changed=0;
        foreach(array_chunk(array_keys($keys),100) as $batch){
            $marks=implode(',',array_fill(0,count($batch),'?'));
            $visible=$table==='shipments'?'IS NOT NULL':'IS NULL';
            $rows=$db->all("SELECT id,payload,version,removed_at FROM shipment_entries WHERE company_id=? AND provider=?
                AND source_key IN ($marks) AND removed_at $visible ORDER BY id".$db->lock(),[$company,$channel,...$batch]);
            foreach($rows as $row){
                $id=(int)$row['id'];
                if($table==='shipments'){
                    if(!self::predates($row['removed_at'],$requestedAt))continue;
                    // Only restore an explicit list deletion, never a removed manual draft or retired source identity.
                    if(!$db->one("SELECT id FROM audit WHERE company_id=? AND record_id=? AND action='shipment.delete'
                            AND user_id IS NOT NULL AND created=? LIMIT 1",[$company,$id,$row['removed_at']]))continue;
                    $set='removed_at=NULL';
                    $params=[];
                }else{
                    $cipher??=new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');
                    $payload=$cipher->open($company,'shipment:'.$id,$row['payload']);
                    $removal=$payload['documentTracking']['removed']['invoice']??null;
                    if(!is_array($removal)||empty($removal['userId'])||!self::predates($removal['at']??null,$requestedAt))continue;
                    unset($payload['documentTracking']['removed']['invoice']);
                    $set='payload=?';
                    $params=[$cipher->seal($company,'shipment:'.$id,$payload)];
                }
                $now=gmdate('c');
                $written=$db->run("UPDATE shipment_entries SET $set,updated=?,version=version+1 WHERE company_id=? AND id=? AND version=?",
                    [...$params,$now,$company,$id,$row['version']]);
                if($written->rowCount()!==1)throw new SyncFailure(409);
                $db->run('INSERT INTO audit(company_id,action,record_id,after_value,created) VALUES(?,?,?,?,?)',
                    [$company,'shipment.table_replay',$id,json_encode(['table'=>$table,'version'=>(int)$row['version']+1],JSON_THROW_ON_ERROR),$now]);
                $changed++;
            }
        }
        return $changed;
    }

    private static function predates(mixed $value,int $requestedAt): bool
    {
        // Stored marks have second resolution. A same-second manual edit wins a race with this job.
        $time=is_string($value)?strtotime($value):false;
        return $time!==false && $time<$requestedAt;
    }
}

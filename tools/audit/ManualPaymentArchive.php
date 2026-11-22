<?php
declare(strict_types=1);
namespace Omnigo\Operations;

use Omnigo\Database;

final class ManualPaymentArchive
{
    public static function archive(Database $db,int $company,array $expected,string $reason): array
    {
        return $db->transaction(function()use($db,$company,$expected,$reason){
            if(!$db->one('SELECT id FROM companies WHERE id=?'.$db->lock(),[$company]))throw new \RuntimeException('Firma bulunamadı.');
            $rows=[];$already=[];
            foreach($expected as $item){
                $row=$db->one('SELECT * FROM records WHERE company_id=? AND id=?'.$db->lock(),[$company,$item['id']]);
                if(!$row)throw new \RuntimeException('Kayıt kontrol sonrasında değişmiş; işlem durduruldu.');
                $value=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);
                if(!empty($value['integration'])||$value['channel']!==$item['channel']||$value['expectedDate']!==$item['expectedDate']||(int)$value['expectedCents']!==$item['expectedCents'])throw new \RuntimeException('Hakediş kapsamı doğrulanamadı.');
                if($row['kind']==='payments'&&!empty($value['archivedAt'])&&($value['archiveSourceHash']??'')===$item['payloadHash']){
                    $audit=$db->one("SELECT before_value FROM audit WHERE company_id=? AND record_id=? AND action IN ('payment.archived','payment.archive_repaired') ORDER BY id LIMIT 1",[$company,$item['id']]);
                    $original=$audit?json_decode($audit['before_value'],true):null;
                    if(!$original||hash('sha256',$original['payload'])!==$item['payloadHash'])throw new \RuntimeException('Arşiv geçmişi doğrulanamadı.');
                    $financial=$value;foreach(['archivedAt','archiveReason','archiveSourceHash','archiveSourceVersion'] as $key)unset($financial[$key]);
                    if($financial!==json_decode($original['payload'],true))throw new \RuntimeException('Arşiv içeriği değişmiş.');
                    $already[]=(int)$row['id'];continue;
                }
                if(!hash_equals($item['payloadHash'],hash('sha256',$row['payload'])))throw new \RuntimeException('Kayıt kontrol sonrasında değişmiş; işlem durduruldu.');
                $repair=$row['kind']===''&&(int)$row['version']===$item['version']+1;
                if($repair){
                    $audit=$db->one("SELECT before_value FROM audit WHERE company_id=? AND record_id=? AND action='payment.archived' ORDER BY id LIMIT 1",[$company,$item['id']]);
                    $original=$audit?json_decode($audit['before_value'],true):null;
                    if(!$original||$original['kind']!=='payments'||hash('sha256',$original['payload'])!==$item['payloadHash'])throw new \RuntimeException('Onarım geçmişi doğrulanamadı.');
                }elseif($row['kind']!=='payments'||(int)$row['version']!==$item['version'])throw new \RuntimeException('Kayıt sürümü değişmiş; işlem durduruldu.');
                $rows[]=['record'=>$row,'expected'=>$item,'repair'=>$repair];
            }
            $archived=[];$at=gmdate('c');
            foreach($rows as $entry){
                $row=$entry['record'];$item=$entry['expected'];
                $value=[...json_decode($row['payload'],true),'archivedAt'=>$at,'archiveReason'=>$reason,'archiveSourceHash'=>$item['payloadHash'],'archiveSourceVersion'=>$item['version']];
                $payload=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $count=$db->run("UPDATE records SET kind='payments',payload=?,version=version+1,updated=? WHERE company_id=? AND id=? AND kind=? AND version=?",[$payload,$at,$company,$row['id'],$row['kind'],$row['version']])->rowCount();
                if($count!==1)throw new \RuntimeException('Kayıt eşzamanlı değişti; işlem geri alındı.');
                $after=['kind'=>'payments','version'=>(int)$row['version']+1,'archivedAt'=>$at,'originalPayloadHash'=>$item['payloadHash'],'reason'=>$reason,'operator'=>'authorized_maintenance'];
                $db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,?,?,?)',[$company,$entry['repair']?'payment.archive_repaired':'payment.archived',$row['id'],json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$at]);
                $stored=$db->one('SELECT kind,payload FROM records WHERE company_id=? AND id=?',[$company,$row['id']]);
                if($stored['kind']!=='payments'||empty(json_decode($stored['payload'],true)['archivedAt']))throw new \RuntimeException('Arşiv doğrulanamadı; işlem geri alındı.');
                $archived[]=(int)$row['id'];
            }
            return ['archived'=>$archived,'alreadyArchived'=>$already,'payloadsPreserved'=>true];
        });
    }
}

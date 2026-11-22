<?php
declare(strict_types=1);
namespace Omnigo\Operations;
use Omnigo\Database;

/** One named synthetic tenant only; any unexpected business row aborts the transaction. */
final class TemporaryTenantCleanup
{
    public const COMPANY=3;
    public const USER=7;
    public const NAME='OG Geçici Sistem Kontrolü 20261101-2305';
    private const NONEMPTY=['users','records','audit','company_profiles','notification_states','order_notifications','support_tickets','inventory_jobs'];
    private const USER_TABLES=['sessions','account_controls','account_profiles','password_resets','mail_outbox','user_preferences','password_change_challenges','admin_mfa_accounts','admin_mfa_challenges','admin_mfa_sessions','user_login_names','user_login_history','inbox_reads'];

    private static function layouts(Database $db):array
    {
        $names=$db->mysql()?array_map(fn($row)=>array_values($row)[0],$db->all('SHOW TABLES')):array_column($db->all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"),'name');
        $result=[];
        foreach($names as $table){
            if(!preg_match('/^[a-z_]+$/D',$table))throw new \RuntimeException('Beklenmeyen tablo adı.');
            $result[$table]=array_column($db->all($db->mysql()?"SHOW COLUMNS FROM `$table`":"PRAGMA table_info(`$table`)"),$db->mysql()?'Field':'name');
        }
        return $result;
    }
    private static function require(bool $ok,string $reason):void
    {
        if(!$ok)throw new \RuntimeException($reason);
    }
    public static function inspect(Database $db):array
    {
        self::require($db->pdo->inTransaction(),'İşlem transaction içinde yapılmalı.');
        $company=$db->one('SELECT id,name FROM companies WHERE id=?'.$db->lock(),[self::COMPANY]);
        self::require(($company['name']??null)===self::NAME,'Geçici müşteri kimliği uyuşmuyor.');
        $users=$db->all('SELECT id,email,role FROM users WHERE company_id=?'.$db->lock(),[self::COMPANY]);
        self::require(count($users)===1&&(int)$users[0]['id']===self::USER&&$users[0]['email']==='og-audit-20261101@example.invalid'&&$users[0]['role']==='client','Geçici kullanıcı kimliği uyuşmuyor.');
        self::require(($db->one('SELECT username FROM user_login_names WHERE user_id=?',[self::USER])['username']??null)==='og.audit.20261101','Geçici kullanıcı adı uyuşmuyor.');
        $layouts=self::layouts($db);$counts=[];
        foreach($layouts as $table=>$columns){
            if(in_array('user_id',$columns,true)&&!in_array($table,[...self::USER_TABLES,'notification_devices','support_messages','audit','notification_states','inbox_jobs'],true))self::require(!$db->one("SELECT user_id FROM `$table` WHERE user_id=? LIMIT 1",[self::USER]),'Beklenmeyen kullanıcı verisi: '.$table);
            if(!in_array('company_id',$columns,true))continue;
            $counts[$table]=(int)$db->one("SELECT COUNT(*) n FROM `$table` WHERE company_id=?",[self::COMPANY])['n'];
            self::require($counts[$table]===0||in_array($table,self::NONEMPTY,true),'Beklenmeyen işletme verisi: '.$table);
            if(in_array('user_id',$columns,true)&&$table!=='users')self::require(!$db->one("SELECT user_id FROM `$table` WHERE user_id=? AND company_id<>? LIMIT 1",[self::USER,self::COMPANY]),'Test kullanıcısı başka müşteride işlem yapmış.');
        }
        $records=$db->all('SELECT kind,payload FROM records WHERE company_id=?',[self::COMPANY]);$payments=0;$channels=0;
        foreach($records as $record){
            $payload=json_decode($record['payload'],true,64,JSON_THROW_ON_ERROR);
            if($record['kind']==='payments'){
                $payments++;
                self::require(($payload['source']??'')==='OG geçici sistem kontrolü — gerçek ödeme değildir'&&(int)($payload['expectedCents']??0)===123&&($payload['expectedDate']??'')==='2026-11-01'&&($payload['channel']??'')==='Hepsiburada'&&($payload['status']??'')==='pending','Hakediş test kaydı değişmiş.');
            }elseif($record['kind']==='channels'){
                $channels++;self::require(($payload['name']??'')==='Hepsiburada','Test mağaza tanımı değişmiş.');
            }elseif($record['kind']==='shipment_auto_since'){
                $start=(new \DateTimeImmutable('2026-11-01 00:00:00',new \DateTimeZone('Europe/Istanbul')))->getTimestamp();
                self::require(count($payload)===1&&($payload['since']??null)===$start,'Test müşterisinin boş sevkiyat başlangıç işareti değişmiş.');
            }else throw new \RuntimeException('Beklenmeyen kayıt türü.');
        }
        self::require($payments===1&&$channels===1,'Test kayıt sayısı değişmiş.');
        $tickets=$db->all('SELECT id,created_by,subject FROM support_tickets WHERE company_id=?',[self::COMPANY]);
        self::require(count($tickets)===1&&(int)$tickets[0]['id']===2&&(int)$tickets[0]['created_by']===self::USER&&$tickets[0]['subject']==='OG geçici canlı eşitleme kontrolü','Destek test kaydı değişmiş.');
        $messages=$db->all('SELECT user_id,body FROM support_messages WHERE ticket_id=2');
        self::require(count($messages)===2,'Test mesaj sayısı değişmiş.');
        foreach($messages as $message)self::require(in_array($message['body'],['Geçici sistem testi. Gerçek destek talebi değildir; kontrol tamamlandığında silinecektir.','Geçici test yanıtı: müşteri-yönetici canlı aktarımı kontrol ediliyor. Gerçek destek işlemi değildir.'],true),'Test mesajı değişmiş.');
        self::require(!$db->one('SELECT m.user_id FROM support_messages m JOIN support_tickets t ON t.id=m.ticket_id WHERE m.user_id=? AND t.company_id<>? LIMIT 1',[self::USER,self::COMPANY]),'Test kullanıcısının başka konuşması var.');
        $job=$db->one('SELECT lease_until FROM inventory_jobs WHERE company_id=?'.$db->lock(),[self::COMPANY]);
        self::require((int)($job['lease_until']??0)<=time(),'Test stok işi çalışıyor; tekrar deneyin.');
        self::require(!$db->one('SELECT id FROM push_deliveries WHERE (event_id IN (SELECT id FROM order_notifications WHERE company_id=?) OR device_id IN (SELECT id FROM notification_devices WHERE user_id=?)) AND state=\'sending\' AND lease_until>?',[self::COMPANY,self::USER,time()]),'Test bildirimi gönderiliyor; tekrar deneyin.');
        return ['companyId'=>self::COMPANY,'userId'=>self::USER,'ticketId'=>2,'companyCounts'=>$counts,'messages'=>2];
    }
    public static function run(Database $db):array
    {
        return $db->transaction(function() use($db){
            $before=self::inspect($db);$removed=[];
            $delete=function(string $table,string $where,array $params)use($db,&$removed){$removed[$table]=$db->run("DELETE FROM `$table` WHERE $where",$params)->rowCount();};
            $delete('push_deliveries','event_id IN (SELECT id FROM order_notifications WHERE company_id=?) OR device_id IN (SELECT id FROM notification_devices WHERE user_id=?)',[self::COMPANY,self::USER]);
            $delete('notification_devices','user_id=?',[self::USER]);
            $delete('support_messages','ticket_id=2',[]);
            $delete('client_session_operators','session_token IN (SELECT token FROM sessions WHERE user_id=?)',[self::USER]);
            $delete('account_audit','actor_id=? OR target_id=?',[self::USER,self::USER]);
            $layouts=self::layouts($db);
            foreach(self::USER_TABLES as $table)if(isset($layouts[$table]))$delete($table,'user_id=?',[self::USER]);
            foreach(['support_tickets','notification_states','order_notifications','audit','records','inventory_jobs','company_profiles'] as $table)$delete($table,'company_id=?',[self::COMPANY]);
            $delete('users','id=? AND company_id=?',[self::USER,self::COMPANY]);
            $delete('companies','id=?',[self::COMPANY]);
            foreach($layouts as $table=>$columns)if(in_array('company_id',$columns,true))self::require(!$db->one("SELECT company_id FROM `$table` WHERE company_id=? LIMIT 1",[self::COMPANY]),'Temizlik doğrulanamadı: '.$table);
            self::require(!$db->one('SELECT id FROM users WHERE id=?',[self::USER]),'Test hesabı kaldı.');
            return ['completedAt'=>gmdate('c'),'scope'=>$before,'removed'=>$removed,'verified'=>true];
        });
    }
}

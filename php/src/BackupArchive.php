<?php
declare(strict_types=1);
namespace Omnigo;

/** Authenticated, size-bounded data archive. Credentials and application files are separate. */
final class BackupArchive
{
    private const BASE_TABLES = ['install_state','companies','users','records','audit','company_profiles','account_controls','jobs','sessions','login_attempts','password_resets','mail_outbox','inbox_threads','inbox_messages','inbox_reads','inbox_jobs','support_tickets','support_messages','notification_states','delivery_orders','delivery_documents','order_notification_sources','order_notifications','finance_entries','finance_sync','notification_devices','push_deliveries','shipment_entries','recent_orders','admin_mfa_accounts','admin_mfa_challenges','admin_mfa_sessions','client_session_operators','user_preferences','password_change_challenges','order_documents','user_login_names','user_login_history','application_preferences'];
    private const EXTENSIONS = [...BackupSchemaExtension::TABLES,MarketplaceListingTransfers::TABLE];
    public const TABLES = [...self::BASE_TABLES,...self::EXTENSIONS];
    private const LEGACY_COUNT = 12;
    public const TRANSIENT = ['sessions','login_attempts','password_resets','mail_outbox','notification_devices','push_deliveries','admin_mfa_challenges','admin_mfa_sessions','client_session_operators','password_change_challenges','chat_presence','chat_push_refs'];
    private const HEADER = "OMNIGO-BACKUP-1\n";
    private const LIMIT = 16777216;

    private static function layouts(): array
    {
        $old=[array_slice(self::TABLES,0,21),array_slice(self::TABLES,0,19),array_slice(self::TABLES,0,18),array_slice(self::TABLES,0,16),array_slice(self::TABLES,0,self::LEGACY_COUNT)];
        $previous=[array_slice(self::TABLES,0,23),...$old,...array_map(fn($tables)=>[...$tables,...array_slice(self::TABLES,21,2)],$old)];
        $finance=[...$previous,...array_map(fn($tables)=>[...$tables,...array_slice(self::TABLES,23,2)],$previous)];
        $push=[...$finance,...array_map(fn($tables)=>[...$tables,...array_slice(self::TABLES,25,2)],$finance)];
        $shipments=[...$push,...array_map(fn($tables)=>[...$tables,self::TABLES[27]],$push)];
        $orders=[...$shipments,...array_map(fn($tables)=>[...$tables,self::TABLES[28]],$shipments)];
        $mfa=[...$orders,...array_map(fn($tables)=>[...$tables,...array_slice(self::TABLES,29,3)],$orders)]; $operators=[...$mfa,...array_map(fn($tables)=>[...$tables,self::TABLES[32]],$mfa)];
        $prior=[...$operators,...array_map(fn($tables)=>[...$tables,self::TABLES[33]],$operators),...array_map(fn($tables)=>[...$tables,...array_slice(self::TABLES,33,2)],$operators)];
        $documents=[...$prior,...array_map(fn($tables)=>[...$tables,'order_documents'],$prior)];
        $identities=[...$documents,...array_map(fn($tables)=>[...$tables,'user_login_names'],$documents)];
        $history=[...$identities,...array_map(fn($tables)=>[...$tables,'user_login_history'],$identities)];
        return [...$history,...array_map(fn($tables)=>[...$tables,'application_preferences'],$history)];
    }

    public static function columns(Database $db, string $table): array
    {
        if (!in_array($table,self::TABLES,true)) throw new \RuntimeException('Bilinmeyen tablo.');
        return array_column($db->all($db->mysql() ? "SHOW COLUMNS FROM `$table`" : "PRAGMA table_info(`$table`)"),$db->mysql() ? 'Field' : 'name');
    }

    public static function checkSchema(Database $db): array
    {
        $tables = $db->mysql()
            ? array_map(fn($row)=>array_values($row)[0],$db->all('SHOW TABLES'))
            : array_column($db->all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"),'name');
        $extensions=array_values(array_intersect(self::EXTENSIONS,$tables));
        $base=array_values(array_diff($tables,$extensions));sort($base);$matched=null;
        foreach(self::layouts() as $schema){$sorted=$schema;sort($sorted);if($base===$sorted){$matched=[...$schema,...$extensions];break;}}
        if ($matched===null) throw new \RuntimeException('Veritabanı şeması yedekleme sürümüyle uyumlu değil.');
        if ($db->mysql()) {
            foreach($db->all('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()') as $table) {
                if (strtoupper($table['ENGINE']??'')!=='INNODB') throw new \RuntimeException('Tutarlı yedek için tüm tablolar InnoDB olmalı.');
            }
        }
        return $matched;
    }

    public static function create(Database $db, string $key): string
    {
        if (strlen($key)!==32) throw new \RuntimeException('Yedek anahtarı geçersiz.');
        $tables=self::checkSchema($db);
        if ($db->mysql()) $db->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $snapshot=$db->transaction(function() use($db,$tables) {
            $result=['format'=>1,'createdAt'=>gmdate('c'),'tables'=>[]]; $size=0;
            foreach($tables as $table) {
                $columns=self::columns($db,$table); $rows=[];
                if (!in_array($table,self::TRANSIENT,true)) {
                    $query=$db->run("SELECT * FROM `$table`");
                    while($row=$query->fetch()) {
                        $size+=strlen(json_encode($row,JSON_THROW_ON_ERROR));
                        if ($size>self::LIMIT-65536) throw new \RuntimeException('Yedek boyut sınırı aşıldı; akış tabanlı yedekleme gerekli.');
                        $rows[]=$row;
                    }
                }
                $result['tables'][$table]=['columns'=>$columns,'rows'=>$rows];
            }
            return $result;
        });
        $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
        if (strlen($json)>self::LIMIT) throw new \RuntimeException('Yedek boyut sınırı aşıldı.');
        $nonce=random_bytes(12); $tag='';
        $cipher=openssl_encrypt(gzencode($json,6),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,self::HEADER);
        if ($cipher===false) throw new \RuntimeException('Yedek şifrelenemedi.');
        return self::HEADER.$nonce.$tag.$cipher;
    }

    public static function read(string $archive, string $key): array
    {
        $offset=strlen(self::HEADER);
        if (strlen($key)!==32 || !str_starts_with($archive,self::HEADER) || strlen($archive)<$offset+29 || strlen($archive)>self::LIMIT+65536) throw new \RuntimeException('Geçersiz yedek.');
        $compressed=openssl_decrypt(substr($archive,$offset+28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($archive,$offset,12),substr($archive,$offset+12,16),self::HEADER);
        if ($compressed===false) throw new \RuntimeException('Yedek doğrulanamadı; anahtar yanlış veya dosya değiştirilmiş.');
        $json=gzdecode($compressed,self::LIMIT);
        if ($json===false) throw new \RuntimeException('Yedek açılamadı.');
        $data=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        $tables=array_keys($data['tables']??[]);$extensions=array_values(array_intersect(self::EXTENSIONS,$tables));$base=array_values(array_diff($tables,$extensions));
        if (($data['format']??null)!==1 || !in_array($base,self::layouts(),true) || $tables!==[...$base,...$extensions]) throw new \RuntimeException('Yedek şeması geçersiz.');
        return $data;
    }

    /** Target must be a freshly migrated, unused database. No overwrite mode exists. */
    public static function restore(Database $target, string $archive, string $key): void
    {
        $data=self::read($archive,$key);
        if(isset($data['tables']['profitability_activation']))ProfitabilitySchema::install($target);
        if(isset($data['tables']['upcoming_payout_reviews']))UpcomingPayoutReviewStore::install($target);
        if(isset($data['tables'][FileCenterSchema::ARCHIVES])||isset($data['tables'][FileCenterSchema::HIDDEN_PRODUCTS]))FileCenterSchema::install($target);
        if(isset($data['tables'][MarketplaceListingTransfers::TABLE]))MarketplaceListingTransfers::install($target);
        $targetTables=self::checkSchema($target);
        foreach(array_keys($data['tables']) as $table) {
            if(!in_array($table,$targetTables,true))throw new \RuntimeException('Hedef veritabanı önce güncellenmeli.');
            if($table==='shipment_entries'&&self::columns($target,$table)!==$data['tables'][$table]['columns']){
                $columns=self::columns($target,$table);
                $oldColumns=$data['tables'][$table]['columns'];$missing=array_slice($columns,count($oldColumns));
                $evolution=['ordered_at','cancelled','shipped','row_status','manual_user_id','create_request','create_hash','removed_at','undo_request','source_key'];
                if(array_slice($columns,0,count($oldColumns))!==$oldColumns||!$missing||array_diff($missing,$evolution)||array_slice($evolution,(int)array_search($missing[0],$evolution,true),count($missing))!==$missing)throw new \RuntimeException('Eski sevkiyat sütunları uyumsuz.');
                $cipher=DeliveryCipher::withKey($key);
                foreach($data['tables'][$table]['rows'] as &$row){
                    if(array_keys($row)!==$data['tables'][$table]['columns'])throw new \RuntimeException('Eski sevkiyat satırı uyumsuz.');
                    foreach($missing as $column)$row[$column]=$column==='ordered_at'?ShipmentOrdering::timestamp($cipher->open((int)$row['company_id'],'shipment:'.$row['id'],$row['payload'])):(in_array($column,['cancelled','shipped'],true)?0:null);
                }unset($row);
                $data['tables'][$table]['columns']=$columns;
            }
            if (self::columns($target,$table)!==$data['tables'][$table]['columns']) throw new \RuntimeException('Hedef tablo sütunları uyumsuz.');
        }
        $target->transaction(function() use($target,$data,$targetTables) {
            foreach($targetTables as $table) {
                $rows=$target->all("SELECT * FROM `$table`".$target->lock());
                $seededInstall=$table==='install_state'&&count($rows)===1&&(int)$rows[0]['id']===1;
                $seededPreference=$table==='application_preferences'&&count($rows)===1&&$rows[0]['preference_key']==='operation_mail_enabled'&&(int)$rows[0]['version']===1;
                if ($rows&&!$seededInstall&&!$seededPreference) throw new \RuntimeException('Hedef boş değil; mevcut verilerin üzerine yazılmadı.');
            }
            $target->run('DELETE FROM install_state');
            if(in_array('application_preferences',$targetTables,true))$target->run('DELETE FROM application_preferences');
            foreach(array_keys($data['tables']) as $table) {
                if (in_array($table,self::TRANSIENT,true)) continue;
                $columns=self::columns($target,$table);
                $sql="INSERT INTO `$table` (`".implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
                foreach($data['tables'][$table]['rows'] as $row) {
                    if (array_keys($row)!==$columns) throw new \RuntimeException('Yedek satırı uyumsuz.');
                    if ($table==='jobs') { $row['state']='blocked'; $row['lease_until']=null; $row['lease_token']=null; $row['error']='Geri yükleme sonrası bağlantı kontrolü gerekiyor.'; }
                    if ($table==='inbox_jobs' && in_array($row['state'],['pending','running','unknown'],true)) { $row['state']=$row['kind']==='reply'?'unknown':'failed'; $row['lease_until']=null;$row['lease_token']=null;$row['error']='Geri yükleme sonrası kontrol gerekiyor. Otomatik işlem yapılmadı.'; }
                    $target->run($sql,array_values(self::restoredRow($table,$row)));
                }
            }
        });
    }

    public static function restoredRow(string $table,array $row): array
    {
        return $table===MarketplaceListingTransfers::TABLE?MarketplaceListingTransfers::restoredRow($row):BackupSchemaExtension::restoredRow($table,$row);
    }
}

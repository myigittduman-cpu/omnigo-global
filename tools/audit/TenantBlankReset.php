<?php
declare(strict_types=1);
namespace Omnigo\Operations;
use Omnigo\Database;

/** Narrow reset for the inspected empty customer; unexpected business rows abort. */
final class TenantBlankReset
{
    public const EMPTY_TABLES=['delivery_documents','delivery_orders','finance_entries','finance_sync','inbox_jobs','inbox_threads','inventory_alerts','inventory_aliases','inventory_balances','inventory_catalog_jobs','inventory_catalog_pages','inventory_catalog_progress','inventory_commands','inventory_issues','inventory_jobs','inventory_ledger','inventory_line_tracking','inventory_links','inventory_listings','inventory_manual_products','inventory_pending_lines','inventory_products','inventory_removed_products','inventory_total_state','inventory_tracking','order_documents','order_notification_sources','recent_orders','shipment_entries','support_tickets'];
    public static function validate(Database $db,int $company):void
    {
        $row=$db->one('SELECT id,name FROM companies WHERE id=?'.$db->lock(),[$company]);
        if($company!==2||($row['name']??'')!=='TERZİOĞLU SHOP')throw new \RuntimeException('Hedef müşteri doğrulanamadı.');
        foreach(self::EMPTY_TABLES as $table)if($db->one("SELECT company_id FROM `$table` WHERE company_id=? LIMIT 1",[$company]))throw new \RuntimeException('Yeni işletme kaydı oluşmuş; sıfırlama durduruldu: '.$table);
        $allowed=['channels','integration_hepsiburada','sync_hepsiburada','integration_health'];
        foreach($db->all('SELECT kind FROM records WHERE company_id=?',[$company]) as $row)if(!in_array($row['kind'],$allowed,true))throw new \RuntimeException('Beklenmeyen müşteri kaydı.');
        foreach($db->all('SELECT state,lease_until FROM jobs WHERE company_id=?'.$db->lock(),[$company]) as $job)if($job['state']==='running'&&(int)$job['lease_until']>time())throw new \RuntimeException('Aktarım halen çalışıyor; sıfırlama yapılmadı.');
        if($db->one("SELECT id FROM order_notifications WHERE company_id=? AND event_type<>'integration'",[$company]))throw new \RuntimeException('Beklenmeyen işletme bildirimi.');
    }
    public static function reset(Database $db,int $company,int $actor):array
    {
        if(!$db->pdo->inTransaction())throw new \LogicException('İşlem transaction içinde yapılmalı.');
        self::validate($db,$company);$removed=[];
        $removed['push_deliveries']=$db->run('DELETE FROM push_deliveries WHERE event_id IN (SELECT id FROM order_notifications WHERE company_id=?)',[$company])->rowCount();
        foreach(['notification_states','order_notifications','jobs','records'] as $table)$removed[$table]=$db->run("DELETE FROM `$table` WHERE company_id=?",[$company])->rowCount();
        $policy=\Omnigo\CustomerInitialPolicy::template();$profile=json_encode(['modules'=>['shipments'=>true,'deliveries'=>true],'workspacePolicy'=>$policy,'initialPasswordSequence'=>0],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $db->run('UPDATE company_profiles SET payload=?,logo=NULL,version=version+1 WHERE company_id=?',[$profile,$company]);
        (new \Omnigo\InventorySettings($db))->configure($company,$policy);
        $db->run('INSERT INTO audit(user_id,company_id,action,record_id,created) VALUES(?,?,?,?,?)',[$actor,$company,'Kullanıcı talebiyle boş başlangıç: mağaza tanımları ve bağlantılar sıfırlandı',$company,gmdate('c')]);
        return $removed;
    }
}

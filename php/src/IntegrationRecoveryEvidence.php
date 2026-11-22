<?php
declare(strict_types=1);
namespace Omnigo;

/** A successful connection alone cannot prove that all pending operational records arrived. */
final class IntegrationRecoveryEvidence
{
    public function __construct(private Database $db,private ?DeliveryCipher $cipher=null){}
    public function ready(int $company,string $provider,array $incident,int $now): bool
    {
        if(!isset(ReconciliationReference::PROVIDERS[$provider]))return true;
        if((new OrderImportQueue($this->db))->count($company,$provider))return false;
        $row=$this->db->one('SELECT payload FROM records WHERE company_id=? AND kind=?',[$company,'sync_'.$provider]);
        if(!$row)return false;$state=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        if(isset($state['scan'])||isset($state['scanEnd'])||isset($state['recentOrders']['scan'])
            ||!empty($state['pendingOrders'])||!empty($state['failure'])||!empty($state['financialSnapshotHeld'])||!empty($state['recentOrders']['historyBackoff']))return false;
        if($provider==='hepsiburada'&&!HepsiburadaReconciliationSource::current($state))return false;
        if($provider==='trendyol'&&!TrendyolReconciliation::current($state,$now))return false;
        $reference=$state['reconciliationReference']??null;if(!$reference)return false;
        if(!empty($reference['shipments'])&&!$this->db->one("SELECT id FROM records WHERE company_id=? AND kind='shipment_auto_since'",[$company]))return false;
        $detected=strtotime($incident['detectedAt']??'')?:0;$captured=strtotime($reference['capturedAt']??'')?:0;
        if(!$detected||$captured<$detected||$now-$captured>SyncFreshness::ALERT_SECONDS
            ||(strtotime($reference['asOf']??'')?:0)<$detected)return false;
        if(isset($state['recentOrders']['watermark'])&&(int)$state['recentOrders']['watermark']<$detected)return false;
        $job=$this->db->one('SELECT state,attempts FROM jobs WHERE company_id=? AND provider=?',[$company,ReconciliationReference::PROVIDERS[$provider]]);
        if(!$job||$job['state']!=='pending'||(int)$job['attempts']!==0)return false;
        $cipher=$this->cipher??new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');
        return (new ReconciliationCompare($this->db,$cipher))->inspect($company,$provider,$reference)['total']===0;
    }
}

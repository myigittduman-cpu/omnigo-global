<?php
declare(strict_types=1);
namespace Omnigo;

/** Order-time prices. Saved events are append-only, under the existing tenant write lock. */
final class ProfitabilityPrices
{
    public function __construct(private Database $db) {}

    public static function clock(): int
    {
        return (int)(new \DateTimeImmutable())->format('Uu');
    }

    public function changed(int $company,string $key,?array $old,int $version,int $amount,int $now): void
    {
        // Capture revisions as soon as the migration is installed, including the activation interval.
        if(!IntegrationImportSchema::tableAvailable($this->db,'profitability_price_versions','version'))return;
        $insert=$this->db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
        if($old&&($date=strtotime($old['updated']))!==false){
            // A pre-migration price's minute and second are known. Its fractional second is not.
            // When an exact event was already written, keep it instead of the approximation.
            $this->db->run($insert.' INTO profitability_price_versions(company_id,product_key,version,effective_at,amount_cents) VALUES(?,?,?,?,?)',
                [$company,$key,(int)$old['version'],($date+1)*1000000-1,(int)$old['amount_cents']]);
        }
        $this->db->run('INSERT INTO profitability_price_versions(company_id,product_key,version,effective_at,amount_cents) VALUES(?,?,?,?,?)',
            [$company,$key,$version,$now,$amount]);
    }

    public function at(int $company,string $key,int $earliest,int $latest): ?int
    {
        $current=$this->db->one('SELECT version,amount_cents,updated FROM inventory_purchase_prices WHERE company_id=? AND product_key=?',
            [$company,$key]);
        if(!$current)return null;
        $start=$earliest;
        $latest=max($start,$latest);
        $scope=[$company,$key];
        $saved='SELECT version,effective_at,amount_cents FROM profitability_price_versions WHERE company_id=? AND product_key=?';
        $before=$this->db->one($saved.' AND effective_at<? ORDER BY effective_at DESC,version DESC LIMIT 1',[...$scope,$start]);
        $fallback=null;
        if(!$this->db->one($saved.' AND version=?',[...$scope,(int)$current['version']])){
            $date=strtotime($current['updated']);
            if($date===false)return null;
            $fallback=($date+1)*1000000-1;
            if($fallback<$start&&($before===null||$fallback>(int)$before['effective_at']))$before=$current;
        }
        if($before===null)return null;
        $amount=(int)$before['amount_cents'];
        if($fallback!==null&&$fallback>=$start&&$fallback<=$latest&&(int)$current['amount_cents']!==$amount)return null;
        $different=$this->db->one($saved.' AND effective_at BETWEEN ? AND ? AND amount_cents<>? LIMIT 1',
            [...$scope,$start,$latest,$amount]);
        return $different===null?$amount:null;
    }
}

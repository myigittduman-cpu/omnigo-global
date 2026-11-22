<?php
declare(strict_types=1);
namespace Omnigo;

/** An import may replace row IDs; warnings follow the tenant/provider/order identity. */
final class InventoryIssues
{
    public function __construct(private Database $db) {}

    public function resolve(int $company,string $provider,string $order,string $legacyKey):void
    {
        if($order!=='') {
            $this->db->run('DELETE FROM inventory_issues WHERE company_id=? AND provider=? AND order_number=?',[$company,$provider,$order]);
        }
        $this->db->run('DELETE FROM inventory_issues WHERE company_id=? AND issue_key=?',[$company,$legacyKey]);
    }

    public function record(int $company,string $provider,string $order,string $legacyKey,string $message):void
    {
        $this->db->transaction(function() use($company,$provider,$order,$legacyKey,$message) {
            $this->resolve($company,$provider,$order,$legacyKey);
            $key=$order!==''?hash('sha256',json_encode([$provider,$order],JSON_THROW_ON_ERROR)):$legacyKey;
            $this->db->run('INSERT INTO inventory_issues(company_id,issue_key,provider,order_number,message,updated) VALUES(?,?,?,?,?,?)',[$company,$key,$provider,$order,mb_substr($message,0,490),gmdate('c')]);
        });
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

/** Store the seller's cost policy independently of marketplace settlements and original snapshots. */
final class ProfitabilityCostHistory
{
    public function __construct(private Database $db) {}

    public function available(): bool
    {
        return IntegrationImportSchema::tableAvailable($this->db,'profitability_cost_rules','version')
            &&IntegrationImportSchema::tableAvailable($this->db,'profitability_line_products','product_key');
    }

    public function record(int $company,string $product,int $version,int $startsAt,int $cents): void
    {
        $this->db->run('INSERT INTO profitability_cost_rules(company_id,product_key,version,starts_at,amount_cents)
            VALUES(?,?,?,?,?)',[$company,$product,$version,$startsAt,$cents]);
    }

    public function bind(int $company,string $provider,string $external,string $line,string $product): void
    {
        $this->db->run('INSERT INTO profitability_line_products(company_id,provider,external_id,line_key,product_key)
            VALUES(?,?,?,?,?)',[$company,$provider,$external,$line,$product]);
    }

    public function amountAt(int $company,string $product,int $orderedAt): ?int
    {
        $rule=$this->db->one('SELECT amount_cents FROM profitability_cost_rules
            WHERE company_id=? AND product_key=? AND starts_at<=? ORDER BY version DESC LIMIT 1',
            [$company,$product,$orderedAt]);
        return $rule===null?null:(int)$rule['amount_cents'];
    }

    public function lines(int $company,array $order,bool $useRules): array
    {
        $scope=[$company,$order['provider'],$order['external_id']];
        $cost='l.unit_purchase_cents';
        $join='';
        if($useRules){
            // Both lookups use complete key prefixes. The newest qualifying decision wins,
            // including a later retroactive decision or two decisions saved in the same second.
            $join=' LEFT JOIN profitability_line_products p ON p.company_id=l.company_id AND p.provider=l.provider
                AND p.external_id=l.external_id AND p.line_key=l.line_key ';
            $cost='COALESCE((SELECT r.amount_cents FROM profitability_cost_rules r
                WHERE r.company_id=l.company_id AND r.product_key=p.product_key AND r.starts_at<=?
                ORDER BY r.version DESC LIMIT 1),l.unit_purchase_cents)';
            array_unshift($scope,(int)$order['ordered_at']);
        }
        return $this->db->all('SELECT l.line_key,l.match_key,l.sku,l.title,l.quantity,'.$cost.' AS unit_purchase_cents
            FROM profitability_lines l '.$join.' WHERE l.company_id=? AND l.provider=? AND l.external_id=?
            ORDER BY l.sku,l.title,l.line_key',$scope);
    }
}

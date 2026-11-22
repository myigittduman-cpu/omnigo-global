<?php
declare(strict_types=1);
namespace Omnigo;

/** Explicit input basis; the existing price history continues to store gross cost. */
final class InventoryPurchaseTax
{
    public static function install(Database $db): void
    {
        if(self::available($db))return;
        $collation=$db->mysql()?$db->one("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_products' AND COLUMN_NAME='product_key'")['COLLATION_NAME']:null;
        if($db->mysql()&&(!is_string($collation)||!preg_match('/^utf8mb4_[a-zA-Z0-9_]+$/D',$collation)))throw new \RuntimeException('inventory_collation_unavailable');
        $db->run('CREATE TABLE inventory_purchase_tax (
            company_id INT NOT NULL,product_key VARCHAR(64) NOT NULL,price_version BIGINT NOT NULL,
            input_cents BIGINT NOT NULL,basis VARCHAR(8) NOT NULL,rate_points INT NOT NULL,
            gross_cents BIGINT NOT NULL,net_cents BIGINT NOT NULL,PRIMARY KEY(company_id,product_key)
        )'.($db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE '.$collation:''));
    }

    public static function available(Database $db): bool
    {
        return IntegrationImportSchema::tableAvailable($db,'inventory_purchase_tax','price_version');
    }

    public static function normalize(array $input): ?array
    {
        if(!array_key_exists('vatBasis',$input)&&!array_key_exists('vatRateBasisPoints',$input))return null;
        $basis=$input['vatBasis']??null;$rate=$input['vatRateBasisPoints']??null;$amount=$input['amountCents']??null;
        if(!in_array($basis,['included','excluded'],true)||!is_int($rate)||$rate<0||$rate>10000||!is_int($amount)||$amount<0||$amount>100000000000)
            throw new \InvalidArgumentException('KDV dahil / hariç seçimini ve KDV oranını kontrol edin.');
        $gross=$basis==='included'?$amount:$amount+ProfitabilityCommission::amount($amount,$rate);
        if($gross>100000000000)throw new \InvalidArgumentException('KDV dahil alış fiyatı üst sınırı aşıyor.');
        $denominator=10000+$rate;
        $net=$basis==='excluded'?$amount:intdiv($amount,$denominator)*10000+intdiv(($amount%$denominator)*10000+intdiv($denominator,2),$denominator);
        return ['purchaseInputCents'=>$amount,'purchaseVatBasis'=>$basis,'purchaseVatRateBasisPoints'=>$rate,'purchaseGrossCents'=>$gross,'purchaseNetCents'=>$net];
    }

    public static function forKeys(Database $db,int $company,array $keys): array
    {
        if(!$keys||!self::available($db))return [];
        $in=implode(',',array_fill(0,count($keys),'?'));$result=[];
        $rows=$db->all('SELECT t.* FROM inventory_purchase_tax t JOIN inventory_purchase_prices p ON p.company_id=t.company_id AND p.product_key=t.product_key AND p.version=t.price_version AND p.amount_cents=t.gross_cents WHERE t.company_id=? AND t.product_key IN ('.$in.')',[$company,...$keys]);
        foreach($rows as $row)$result[$row['product_key']]=[
            'purchaseInputCents'=>(int)$row['input_cents'],'purchaseVatBasis'=>$row['basis'],'purchaseVatRateBasisPoints'=>(int)$row['rate_points'],
            'purchaseGrossCents'=>(int)$row['gross_cents'],'purchaseNetCents'=>(int)$row['net_cents'],
        ];
        return $result;
    }

    public static function save(Database $db,int $company,string $key,int $version,array $tax): void
    {
        $values=[$version,$tax['purchaseInputCents'],$tax['purchaseVatBasis'],$tax['purchaseVatRateBasisPoints'],$tax['purchaseGrossCents'],$tax['purchaseNetCents'],$company,$key];
        $existing=$db->one('SELECT price_version FROM inventory_purchase_tax WHERE company_id=? AND product_key=?',[$company,$key]);
        if($existing)$db->run('UPDATE inventory_purchase_tax SET price_version=?,input_cents=?,basis=?,rate_points=?,gross_cents=?,net_cents=? WHERE company_id=? AND product_key=?',$values);
        else $db->run('INSERT INTO inventory_purchase_tax(price_version,input_cents,basis,rate_points,gross_cents,net_cents,company_id,product_key) VALUES(?,?,?,?,?,?,?,?)',$values);
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

/** Reads existing snapshots only. No writes to order/delivery/import tables. */
final class InventorySource
{
    public function __construct(private Database $db){}
    public function batch(int $company,int $after):array
    {
        $external=$this->db->mysql()?"JSON_UNQUOTE(JSON_EXTRACT(r.payload,'$.externalId'))":"JSON_EXTRACT(r.payload,'$.externalId')";
        $kind=$this->db->mysql()?"CONCAT('order_',a.provider)":"('order_'||a.provider)";
        $source='records';
        if(RecentOrderSchema::available($this->db))$source="(SELECT id,company_id,kind,payload FROM records UNION ALL SELECT 1000000000000+a.id,a.company_id,$kind,a.payload FROM recent_orders a WHERE NOT EXISTS(SELECT 1 FROM records r WHERE r.company_id=a.company_id AND r.kind=$kind AND $external=a.external_id)) sources";
        $kinds=array_map(fn($p)=>'order_'.$p,array_keys(OrderList::PROVIDERS));
        return $this->db->all('SELECT id,kind,payload FROM '.$source.' WHERE company_id=? AND id>? AND kind IN ('.implode(',',array_fill(0,count($kinds),'?')).') ORDER BY id LIMIT 40',[$company,$after,...$kinds]);
    }
}

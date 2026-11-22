<?php
declare(strict_types=1);
namespace Omnigo;

/** Reservations for uncounted products. Called under the job and product locks. */
final class InventoryPending
{
    public function __construct(private Database $db){}
    public function observe(int $company,array $product,array $line,?array $old):void
    {
        if($product['quantity']!==null)return;
        $tracking=$this->db->one('SELECT started_at FROM inventory_tracking WHERE company_id=?',[$company]);
        if(!$tracking){$tracking=['started_at'=>gmdate('c')];$this->db->run('INSERT INTO inventory_tracking(company_id,started_at) VALUES(?,?)',[$company,$tracking['started_at']]);}
        $entry=$this->db->one('SELECT quantity FROM inventory_pending_lines WHERE company_id=? AND provider=? AND line_key=?',[$company,$line['provider'],$line['lineKey']]);
        $net=$line['ordered']-$line['cancelled']-$line['returned'];
        if($entry&&$old)$quantity=max(0,min($net,(int)$entry['quantity']+$net-(int)$old['consumed']));
        else{
            $recent=new \DateTimeImmutable($line['placedAt'])>=new \DateTimeImmutable($tracking['started_at']);
            // Discovery does not charge historical open orders from the initial import.
            $quantity=$recent?$net:0;
        }
        if(!$entry&&$quantity===0)return;
        if($entry&&(int)$entry['quantity']===$quantity)return;
        if($entry)$this->db->run('UPDATE inventory_pending_lines SET quantity=? WHERE company_id=? AND provider=? AND line_key=?',[$quantity,$company,$line['provider'],$line['lineKey']]);
        else $this->db->run('INSERT INTO inventory_pending_lines(company_id,provider,line_key,product_key,quantity) VALUES(?,?,?,?,?)',[$company,$line['provider'],$line['lineKey'],$product['product_key'],$quantity]);
        $this->db->run('UPDATE inventory_products SET version=version+1 WHERE company_id=? AND product_key=?',[$company,$product['product_key']]);
    }
    public function quantity(int $company,string $product):int
    {
        return (int)$this->db->one('SELECT COALESCE(SUM(quantity),0) n FROM inventory_pending_lines WHERE company_id=? AND product_key=?',[$company,$product])['n'];
    }
    public function initialize(int $company,string $product):void
    {
        foreach($this->db->all('SELECT provider,line_key,quantity FROM inventory_pending_lines WHERE company_id=? AND product_key=?',[$company,$product]) as $line){
            $this->db->run('DELETE FROM inventory_line_tracking WHERE company_id=? AND provider=? AND line_key=?',[$company,$line['provider'],$line['line_key']]);
            $this->db->run('INSERT INTO inventory_line_tracking(company_id,provider,line_key,chargeable) VALUES(?,?,?,?)',[$company,$line['provider'],$line['line_key'],(int)$line['quantity']>0?1:0]);
        }
        $this->db->run('DELETE FROM inventory_pending_lines WHERE company_id=? AND product_key=?',[$company,$product]);
    }
}

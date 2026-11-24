<?php
declare(strict_types=1);
namespace Omnigo;

/** Tenant-scoped audit reads; keyset paging stays stable when new movements arrive. */
final class InventoryHistory
{
    public function __construct(private Database $db){}

    public function listing(int $company,string $product='',string $from='',string $to='',string $before='',?array $visibility=null,bool $marketplace=false):array
    {
        $where=['l.company_id=?'];$params=[$company];
        if($visibility!==null){
            if(!$visibility['returns'])$where[]="l.kind NOT IN ('store_return','horoz_return')";
            if(!$visibility['returns']||!$visibility['cancellations'])$where[]="l.kind<>'order_restore'";
        }
        if($product!==''){
            (new InventoryStock($this->db))->product($company,$product);
            $where[]=InventoryStockCard::productKeySql($this->db,'l').'=?';$params[]=InventoryStockCard::primaryKey($this->db,$company,$product);
        }
        $cursorScope=implode(' AND ',$where);$cursorParams=$params;
        $start=$this->day($from);$end=$this->day($to);
        if($start&&$end&&$start>$end)throw new \InvalidArgumentException('Başlangıç tarihi bitişten sonra olamaz.');
        if($start){$where[]='l.created>=?';$params[]=$this->utc($start);}
        if($end){$where[]='l.created<?';$params[]=$this->utc($end->modify('+1 day'));}
        $source=$marketplace?MarketplaceStockHistory::source():'inventory_ledger l';
        if($before!==''){
            if(!preg_match($marketplace?'/^[a-zA-Z0-9-]{16,80}$/D':'/^[a-f0-9]{64}$/D',$before))throw new \InvalidArgumentException('Hareket sayfası geçersiz.');
            $cursor=$this->db->one('SELECT l.created FROM '.$source.' WHERE '.$cursorScope.' AND l.event_key=?',[...$cursorParams,$before]);
            if(!$cursor)throw new \InvalidArgumentException('Hareket sayfası geçersiz.');
            $where[]='(l.created<? OR (l.created=? AND l.event_key<?))';
            array_push($params,$cursor['created'],$cursor['created'],$before);
        }
        $extra=$marketplace?',l.request_state,l.request_error,l.request_quantity,l.request_source,l.request_updated':'';
        $rows=$this->db->all('SELECT l.event_key,l.product_key,l.kind,l.delta,l.quantity_after,l.note,l.created,l.actor_id,u.name AS actor_name,p.title,p.sku'.$extra.' FROM '.$source.' LEFT JOIN users u ON u.id=l.actor_id LEFT JOIN inventory_products p ON p.company_id=l.company_id AND p.product_key=l.product_key WHERE '.implode(' AND ',$where).' ORDER BY l.created DESC,l.event_key DESC LIMIT 51',$params);
        $more=count($rows)>50;if($more)array_pop($rows);
        foreach($rows as &$row){
            $row['actor_name']=$row['actor_name']?:($row['actor_id']===null?'Sistem':'Kullanıcı #'.$row['actor_id']);
            if($marketplace&&$row['kind']==='marketplace_stock_update'){$row=MarketplaceStockHistory::decorate($row);continue;}
            $row['delta']=(int)$row['delta'];$row['quantity_after']=(int)$row['quantity_after'];
            $row['quantity_before']=in_array($row['kind'],['initialize','manual_create','horoz_initialize'],true)?null:$row['quantity_after']-$row['delta'];
            $row['actor_name']=$row['actor_name']?:($row['actor_id']===null?'Sistem':'Kullanıcı #'.$row['actor_id']);
        }unset($row);
        if($marketplace)$rows=MarketplaceStockHistory::hidePreviousRetries($this->db,$company,$rows);
        return ['items'=>$rows,'nextCursor'=>$more?$rows[count($rows)-1]['event_key']:null];
    }

    private function day(string $value):?\DateTimeImmutable
    {
        if($value==='')return null;
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Europe/Istanbul'));
        if(!$day||$day->format('Y-m-d')!==$value)throw new \InvalidArgumentException('Tarih geçersiz.');
        return $day;
    }
    private function utc(\DateTimeImmutable $date):string{return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');}
}

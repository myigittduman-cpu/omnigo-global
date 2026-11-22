<?php
declare(strict_types=1);
namespace Omnigo;

/** Publishes completed monthly snapshots into the existing sales store shared by both portals. */
final class HepsiburadaSalesPublisher
{
    public function __construct(private Database $db){}
    public function publish(int $company,string $from,string $through,array $refunds):array
    {
        if(!$this->db->pdo->inTransaction())throw new \LogicException('Satış yayını işlem kilidi gerektirir.');
        $start=new \DateTimeImmutable($from);$end=new \DateTimeImmutable($through);$zone=new \DateTimeZone('Europe/Istanbul');
        $months=[];for($month=$start;$month<=$end;$month=$month->modify('first day of next month')->setTime(0,0))$months[$month->format('Y-m')]=[...SalesCalendar::ZERO,'days'=>[]];
        $byOrder=[];foreach($refunds as $claim){if(new \DateTimeImmutable($claim['orderedAt'])<$start)continue;$byOrder[$claim['orderNumber']][]=$claim;}
        $statement=$this->db->run("SELECT payload FROM recent_orders WHERE company_id=? AND provider='hepsiburada' ORDER BY id",[$company]);
        $orderCount=0;$published=[];$projection=new HepsiburadaSalesOrders($this->db);
        while($row=$statement->fetch()){
            $order=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$date=(new \DateTimeImmutable($order['createdAt']))->setTimezone($zone);
            if($date<$start||$date>$end)continue;
            $order=HepsiburadaClaims::apply($order,$byOrder[$order['externalId']]??[]);unset($byOrder[$order['externalId']]);
            $projection->save($company,$order,$through);$published[$order['externalId']]=true;
            $month=$date->format('Y-m');$day=$date->format('Y-m-d');$months[$month]['days'][$day]??=SalesCalendar::ZERO;
            SalesCalendar::add($months[$month],$order);SalesCalendar::add($months[$month]['days'][$day],$order);$orderCount++;
        }
        if($byOrder)throw new SyncFailure(409);
        $projection->removeMissing($company,$start,$end,$published);
        $existing=[];foreach($this->db->all("SELECT id,payload FROM records WHERE company_id=? AND kind='sales'",[$company]) as $row){
            $sale=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);if(($sale['channel']??'')!=='Hepsiburada')continue;
            if(isset($existing[$sale['month']]))throw new SyncFailure(409);$existing[$sale['month']]=$row;
        }
        foreach($months as $month=>$values){
            $sale=['channel'=>'Hepsiburada','month'=>$month,...array_intersect_key($values,SalesCalendar::ZERO),'integration'=>'hepsiburada','refundsVerified'=>true,
                'source'=>'Hepsiburada siparişleri ve gerçekleşmiş ücret iadeleri','dailySales'=>['from'=>$from,'through'=>$through,'days'=>$values['days']]];
            $json=json_encode($sale,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$old=$existing[$month]??null;
            if($old){
                if(empty(json_decode($old['payload'],true)['integration']))$this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(NULL,?,?,?,?,?,?)',[$company,'hepsiburada.sales_takeover',$old['id'],$old['payload'],$json,gmdate('c')]);
                $this->db->run('UPDATE records SET payload=?,updated=?,version=version+1 WHERE id=?',[$json,$through,$old['id']]);
            }else $this->db->run("INSERT INTO records(company_id,kind,payload,updated) VALUES(?,'sales',?,?)",[$company,$json,$through]);
        }
        return ['orders'=>$orderCount,'months'=>count($months),'refundClaims'=>count($refunds)];
    }
}

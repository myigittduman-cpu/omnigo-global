<?php
declare(strict_types=1);
namespace Omnigo;

/** Read-only, integer-cents comparisons. Recent arrivals are intentionally separate from published orders. */
final class ReconciliationCompare
{
    private array $issues=[];
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function inspect(int $company,string $provider,array $reference): array
    {
        $this->issues=[];$actual=[];$months=[];
        $stored=in_array($provider,['hepsiburada','pttavm','trendyol'],true)
            ?$this->db->all("SELECT id,payload FROM recent_orders WHERE company_id=? AND provider=?",[$company,$provider])
            :$this->db->all('SELECT id,payload FROM records WHERE company_id=? AND kind=?',[$company,'order_'.$provider]);
        foreach($stored as $row){
            $value=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$key=ReconciliationReference::key((string)$value['externalId']);
            if($provider==='hepsiburada'){
                $value['month']=substr(SalesCalendar::day($value['createdAt']),0,7);
                try{$value=HepsiburadaClaims::apply($value,$reference['refunds'][$value['externalId']]??[]);}
                catch(SyncFailure){$this->add('amount',['area'=>'orders','reference'=>$value['externalId'],'field'=>'refundValidation']);}
            }
            $actual[$key][]=['id'=>(int)$row['id'],'value'=>$value];
        }
        foreach($reference['orders'] as $key=>$expected){
            $rows=$actual[$key]??[];$context=['area'=>'orders','reference'=>$expected['reference']];
            if(!$rows)$this->add('missing',$context);
            elseif(count($rows)>1)$this->add('duplicate',[...$context,'count'=>count($rows)]);
            foreach($rows as $row){$money=ReconciliationReference::money($row['value']);
                if($money!==$expected['money'])$this->add('amount',[...$context,'recordId'=>$row['id'],'expected'=>$expected['money'],'actual'=>$money]);
                if($row['value']['month']!==$expected['month'])$this->add('period',[...$context,'recordId'=>$row['id']]);
                if(isset($expected['state'])&&($row['value']['state']??null)!==$expected['state'])$this->add('status',[...$context,'recordId'=>$row['id']]);
            }
            $months[$expected['month']]??=[0,0,0,0];foreach($expected['money'] as $i=>$amount)$months[$expected['month']][$i]+=$amount;
            unset($actual[$key]);
        }
        foreach($actual as $rows)foreach($rows as $row){
            // Rows outside the source coverage are not compared to an incomplete date range.
            $created=strtotime($row['value']['createdAt']);
            if($created>=strtotime($reference['from'])&&$created<=strtotime($reference['asOf']))$this->add('unexpected',['area'=>'orders','reference'=>(string)$row['value']['externalId'],'recordId'=>$row['id']]);
        }
        if(!in_array($provider,['idefix','pttavm','trendyol'],true)){
            $zone=new \DateTimeZone('Europe/Istanbul');
            $start=(new \DateTimeImmutable($reference['from']))->setTimezone($zone)->modify('first day of this month')->setTime(0,0);
            $end=(new \DateTimeImmutable($reference['asOf']))->setTimezone($zone);
            for($date=$start;$date<=$end;$date=$date->modify('+1 month'))$months[$date->format('Y-m')]??=[0,0,0,0];
            $sales=[];
            foreach($this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='sales'",[$company]) as $row){$value=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);if(($value['channel']??'')===ReconciliationReference::PROVIDERS[$provider])$sales[$value['month']][]=$value;}
            foreach($months as $month=>$money){$rows=$sales[$month]??[];$context=['area'=>'sales','reference'=>$month];
                if(!$rows)$this->add('missing',$context);elseif(count($rows)>1)$this->add('duplicate',[...$context,'count'=>count($rows)]);
                foreach($rows as $value)if(ReconciliationReference::money($value)!==$money)$this->add('amount',[...$context,'expected'=>$money,'actual'=>ReconciliationReference::money($value)]);
            }
        }
        (new ReconciliationDownstream($this->db,$this->cipher))->compare($company,$provider,$reference,fn($type,$detail)=>$this->add($type,$detail));
        usort($this->issues,static fn($a,$b)=>strcmp(json_encode(array_diff_key($a,['recordId'=>true])),json_encode(array_diff_key($b,['recordId'=>true]))));
        $identity=array_map(static fn($issue)=>array_diff_key($issue,['recordId'=>true]),$this->issues);
        $counts=array_fill_keys(['missing','duplicate','amount','unexpected','period','status'],0);foreach($this->issues as $issue)$counts[$issue['type']]++;
        return ['state'=>$this->issues?'issue':'healthy','checkedAt'=>gmdate('c'),'sourceAsOf'=>$reference['asOf'],'orderCount'=>count($reference['orders']),
            'counts'=>$counts,'total'=>count($this->issues),'fingerprint'=>hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR)),
            'items'=>array_slice($this->issues,0,50),'truncated'=>count($this->issues)>50];
    }
    private function add(string $type,array $detail): void{$this->issues[]=['type'=>$type,...$detail];}
}

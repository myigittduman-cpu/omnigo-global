<?php
declare(strict_types=1);
namespace Omnigo;

/** Checks the stored records against the published summary, not against the remote marketplace. */
final class KoctasDiagnostics
{
    public function __construct(private Database $db) {}
    public function report(int $company,string $month): array
    {
        $zero=['grossCents'=>0,'cancelCents'=>0,'returnCents'=>0,'orders'=>0];
        $totals=$zero;$samples=[];$states=[];$stored=0;
        $rows=$this->db->all("SELECT payload FROM records WHERE company_id=? AND kind='order_koctas'",[$company]);
        foreach($rows as $row) {
            $value=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
            if (($value['month']??'')!==$month) continue;
            $stored++;
            foreach(array_keys($zero) as $key) $totals[$key]+=$value[$key];
            $state=$value['state']??'UNKNOWN';$states[$state]=($states[$state]??0)+1;
            $samples[]=array_intersect_key($value,array_flip(['externalId','createdAt','state','grossCents','cancelCents','returnCents']));
        }
        usort($samples,fn($a,$b)=>strcmp($a['createdAt'],$b['createdAt']));
        $published=null;
        foreach($this->db->all("SELECT payload,updated FROM records WHERE company_id=? AND kind='sales'",[$company]) as $row) {
            $value=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
            if (($value['channel']??'')==='Koçtaş' && ($value['month']??'')===$month) {
                $published=array_intersect_key($value,array_flip(['grossCents','cancelCents','returnCents','orders','source','integration']));
                $published['updated']=$row['updated'];
            }
        }
        $sync=$this->db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_koctas'",[$company]);
        $state=$sync?json_decode($sync['payload'],true,16,JSON_THROW_ON_ERROR):[];
        $differences=[];
        if ($published) foreach(array_keys($zero) as $key) $differences[$key]=$published[$key]-$totals[$key];
        $consistent=$published && ($published['integration']??'')==='koctas' && !array_filter($differences);
        $totals['netCents']=$totals['grossCents']-$totals['cancelCents']-$totals['returnCents'];
        return ['month'=>$month,'storedOrders'=>$stored,'storedTotals'=>$totals,'published'=>$published,
            'differences'=>$differences,'localTotalsMatch'=>(bool)$consistent,'marketplaceVerified'=>false,
            'scanInProgress'=>isset($state['scanEnd']),'lastSuccessAt'=>$state['lastSuccessAt']??null,
            'startDate'=>$state['startDate']??null,'states'=>$states,'sampleOrders'=>array_slice($samples,0,100),
            'sampleTruncated'=>count($samples)>100,
            'job'=>$this->db->one("SELECT state,error,next_run FROM jobs WHERE company_id=? AND provider='Koçtaş'",[$company])];
    }
}

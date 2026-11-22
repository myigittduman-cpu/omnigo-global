<?php
declare(strict_types=1);
namespace Omnigo;

/** Bounded, tenant-scoped local reads; no API calls, writes, jobs or credential changes. */
final class UndeliveredSalesReader
{
    private array $companies;
    public function __construct(private Database $db,private ?DeliveryCipher $cipher=null,?array $companies=null)
    {
        $path=dirname(__DIR__).'/storage/undelivered-sales-policy.json';
        $this->companies=$companies??(is_file($path)?json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR)['companyIds']:[]);
    }
    public function enabled(int $company):bool{return in_array($company,$this->companies,true);}

    public function orders(int $company,string $provider,iterable $rows):\Generator
    {
        $batch=[];
        foreach($rows as $row){
            $batch[]=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
            if(count($batch)===100){yield from $this->batch($company,$provider,$batch);$batch=[];}
        }
        if($batch)yield from $this->batch($company,$provider,$batch);
    }
    private function batch(int $company,string $provider,array $orders):\Generator
    {
        if(!$this->enabled($company)){foreach($orders as $order)yield ['source'=>$order,'order'=>$order];return;}
        $label=array_search($provider,MarketplaceSalesSummary::PROVIDERS,true);
        if($label===false)throw new \LogicException('Satış kaynağı tanımlı değil.');
        $clauses=[];$args=[$company,$label];$split=in_array($provider,['n11','idefix','hepsiburada'],true);
        foreach(array_unique(array_column($orders,'externalId')) as $id){
            $clauses[]='external_id=?';$args[]=$id;
            if($split){$clauses[]="external_id LIKE ? ESCAPE '!'";$args[]=strtr((string)$id,['!'=>'!!','%'=>'!%','_'=>'!_']).':%';}
        }
        $cipher=$this->cipher??=new DeliveryCipher(dirname(__DIR__).'/storage/backup.key');$packages=[];
        foreach($this->db->all('SELECT external_id,payload FROM delivery_orders WHERE company_id=? AND provider=? AND ('.implode(' OR ',$clauses).')',$args) as $row){
            $packages[$row['external_id']]=$cipher->open($company,'order:'.$label.':'.$row['external_id'],$row['payload']);
        }
        foreach($orders as $order){
            $id=(string)$order['externalId'];$matched=[];
            foreach($packages as $key=>$package)if((string)$key===$id||($split&&str_starts_with((string)$key,$id.':')))$matched[]=$package;
            yield ['source'=>$order,'order'=>UndeliveredSales::apply($label,$order,$matched)];
        }
    }
}

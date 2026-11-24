<?php
declare(strict_types=1);
namespace Omnigo;

/** Separate resumable queue; imports continue unchanged if this worker stops. */
final class InventoryWorker
{
    public function __construct(private Database $db,private DeliveryCipher $cipher){}
    public function run(int $company,int $deadline):array
    {
        $lease=bin2hex(random_bytes(20));
        $job=$this->db->transaction(function()use($company,$lease){
            $job=$this->db->one('SELECT * FROM inventory_jobs WHERE company_id=?'.$this->db->lock(),[$company]);
            if(!$job||!(int)$job['enabled']||(int)$job['lease_until']>time()||(int)$job['next_run']>time())return null;
            if((int)$job['cursor_id']===0)$job['sequence_no']++;
            $this->db->run('UPDATE inventory_jobs SET lease_token=?,lease_until=?,sequence_no=? WHERE company_id=?',[$lease,time()+100,$job['sequence_no'],$company]);return $job;
        });
        if(!$job)return ['skipped'=>true];$processed=0;$cursor=(int)$job['cursor_id'];$done=false;
        try{
            $settings=new InventorySettings($this->db);
            (new InventoryCommands($this->db))->process($company,$lease);
            $online=$settings->marketplaceEnabled($company);
            if($online)(new InventoryMatching($this->db))->process($company,$lease);
            else $done=true;
            $details=new OrderDetails($this->db,$this->cipher);$returns=[];$identity=new InventoryOrderIdentity($this->db);
            while($online&&time()+3<$deadline){
                $batch=(new InventorySource($this->db))->batch($company,$cursor);
                if(!$batch){$done=true;break;}
                foreach($batch as $row){
                    if(time()+3>=$deadline)break 2;
                    $provider=OrderList::PROVIDERS[substr($row['kind'],6)];$issue=hash('sha256',$row['kind'].':'.$row['id']);$number='';
                    try{
                        $order=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);$number=(string)($order['externalId']??'');
                        if(($order['orders']??0)>0){
                            $returns[$provider]??=ApprovedReturnIndex::snapshot($this->db,$company,$provider);
                            $identified=$identity->enrich($company,$provider,$order);
                            if($provider==='PTT AVM')foreach($identified['products']??[] as $index=>$product){
                                // The catalog filled this missing source field with a verified seller code.
                                if($product['sku']!==($order['products'][$index]['sku']??''))
                                    $identified['products'][$index]['sellerStockCode']=$product['sku'];
                            }
                            $packages=$identity->packages($provider,$identified,$details->packages($company,$provider,$number));
                            $lines=InventoryOrderLines::normalize($provider,$identified,$packages,$returns[$provider]);
                            $resolved=[];
                            $products=new InventoryOrderProduct($this->db);
                            foreach($lines as $line){
                                $key=$products->resolve($company,$line);
                                // Keep the existing source fingerprint and financial quantities on replay.
                                unset($line['sellerStockCode']);
                                $resolved[]=[...$line,'productKey'=>$key];
                            }
                            foreach($resolved as $line)(new InventoryProjection($this->db))->apply($company,$line,(int)$job['sequence_no'],$lease);
                        }
                        (new InventoryIssues($this->db))->resolve($company,$provider,$number,$issue);
                    }catch(\DomainException|\InvalidArgumentException $error){
                        (new InventoryIssues($this->db))->record($company,$provider,$number,$issue,$error->getMessage());
                    }
                    $cursor=(int)$row['id'];$processed++;
                    $this->db->run('UPDATE inventory_jobs SET cursor_id=? WHERE company_id=? AND lease_token=?',[$cursor,$company,$lease]);
                }
            }
            if($done)$this->db->run('UPDATE inventory_jobs SET cursor_id=0,last_success=?,error=NULL,attempts=0 WHERE company_id=? AND lease_token=?',[gmdate('c'),$company,$lease]);
            $this->db->run('UPDATE inventory_jobs SET lease_until=0,lease_token=NULL,next_run=? WHERE company_id=? AND lease_token=?',[time()+($done?15:1),$company,$lease]);
            return ['processed'=>$processed,'complete'=>$done];
        }catch(\Throwable $error){
            $this->db->run('UPDATE inventory_jobs SET lease_until=0,lease_token=NULL,attempts=attempts+1,next_run=?,error=? WHERE company_id=? AND lease_token=?',[time()+min(900,30*2**min(5,(int)$job['attempts'])),'Stok kontrolü tamamlanamadı; otomatik tekrar denenecek.',$company,$lease]);throw $error;
        }
    }
}

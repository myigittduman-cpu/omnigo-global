<?php
declare(strict_types=1);
namespace Omnigo;

/** Use the existing outboxes, advancing a card only after the current marketplace settles. */
final class MarketplaceListingTransferWorker
{
    private const TERMINAL=['succeeded','failed','uncertain'];

    public function __construct(private Database $db,private MarketplaceStockPilot $pilot){}

    public function tick(): bool
    {
        if(!(new MarketplaceListingTransfers($this->db))->installed())return false;
        $row=$this->db->one('SELECT * FROM marketplace_listing_transfers WHERE done=0 AND due<=? ORDER BY due,created LIMIT 1',[time()]);
        if(!$row)return false;
        $plan=json_decode($row['payload'],true,64,JSON_THROW_ON_ERROR);
        foreach($plan['lines'] as &$line){
            if(!in_array($line['state'],['waiting','sending'],true))continue;
            $this->advance($row,$line,$plan['targets'][$line['provider']]);
            break;
        }
        unset($line);
        $waiting=(bool)array_filter($plan['lines'],static fn($line)=>$line['state']==='waiting');
        $sending=(bool)array_filter($plan['lines'],static fn($line)=>$line['state']==='sending');
        $this->db->run('UPDATE marketplace_listing_transfers SET payload=?,done=?,due=? WHERE company_id=? AND request_id=?',
            [json_encode($plan,JSON_THROW_ON_ERROR),(int)!($waiting||$sending),$sending?time()+5:0,$row['company_id'],$row['request_id']]);
        return true;
    }

    private function advance(array $row,array &$line,array $approved): void
    {
        $company=(int)$row['company_id'];$id=(int)$row['actor_id'];
        $actor=$this->db->one('SELECT id,role FROM users WHERE id=?',[$id])??[];
        $canSend=($actor['role']??null)==='admin'&&(new Security($this->db,[]))->active($id)
            &&MarketplaceStockJob::allowed($this->db,$this->pilot,$company)
            &&time()-(int)($row['created']/1000000)<5400;
        $current=[];
        foreach((new ActiveMarketplaceListings($this->db))->writableCardTargets($company,$row['card_key']) as $candidate){
            $current[$candidate['provider']][$candidate['key']]=$candidate;
        }
        $previous=[];
        foreach($line['details'] as $detail)$previous[$detail['sku']][$detail['kind']]=$detail;
        $line['details']=[];
        foreach($approved as $target){
            $candidate=$current[$target['provider']][$target['key']]??null;
            $prior=$previous[$target['sku']]??[];
            if($row['quantity']!==null)$line['details'][]=$this->operation($row,$target,$candidate,$actor,$canSend,'stock',$prior['stock']??null);
            if($row['price']!==null)$line['details'][]=$this->operation($row,$target,$candidate,$actor,$canSend,'price',$prior['price']??null);
        }
        $states=array_column($line['details'],'state');
        if(array_diff($states,self::TERMINAL))$line['state']='sending';
        elseif(array_diff($states,['succeeded']))$line['state']='failed';
        else $line['state']='succeeded';
    }

    private function operation(array $row,array $target,?array $current,array $actor,bool $canSend,string $kind,?array $prior): array
    {
        $company=(int)$row['company_id'];$channel=array_search($target['provider'],MarketplaceStockJob::CHANNELS,true);
        $id=substr(hash('sha256',$row['request_id'].':'.$kind.':'.$channel.':'.$target['key']),0,32);
        $detail=['kind'=>$kind,'sku'=>$target['sku']];
        $existing=$kind==='stock'
            ?$this->db->one('SELECT state,error message FROM marketplace_stock_requests WHERE company_id=? AND request_id=?',[$company,'pilot:'.$channel.':'.$id])
            :(IntegrationImportSchema::tableAvailable($this->db,'marketplace_price_jobs','state')?(new MarketplacePriceQueue($this->db))->results($company,$id)[0]??null:null);
        if($existing)return [...$detail,'state'=>$existing['state'],'message'=>$existing['message']??null];
        // Persist a declined operation while its companion is in flight. It must not retry after an identity or permission change.
        if(($prior['state']??null)==='failed')return $prior;
        $safety=['provider','sku','productKey','remoteId','catalogSku'];
        foreach($safety as $field){
            if(($current[$field]??null)!==($target[$field]??null))$canSend=false;
        }
        $connection=$this->db->one('SELECT version FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$channel]);
        if(!$canSend||(int)($connection['version']??-1)!==$target['connectionVersion']){
            return [...$detail,'state'=>'failed','message'=>'İlan, bağlantı, yetki veya gönderim süresi yeniden doğrulanmalı.'];
        }
        $input=['productKey'=>$target['productKey'],'requestId'=>$id,'confirmed'=>true];
        try{
            if($kind==='stock'){
                $stock=new MarketplaceStockQueue($this->db,new MarketplaceStockPolicy($this->db),$this->pilot);
                $job=$stock->submit($company,[...$input,'listingKey'=>$target['key'],'provider'=>$target['provider'],'sourceQuantity'=>$target['quantity'],
                    'identityToken'=>$target['identityToken']??null,'quantity'=>(int)$row['quantity']],$actor);
                return [...$detail,'state'=>$job['state'],'message'=>$job['error']??null];
            }
            $job=(new MarketplacePriceQueue($this->db))->add($company,[...$input,'priceCents'=>(int)$row['price']],$actor,$target)[0];
            return [...$detail,'state'=>$job['state'],'message'=>$job['message']??null];
        }catch(\InvalidArgumentException|\DomainException|\OutOfBoundsException $error){
            return [...$detail,'state'=>'failed','message'=>$error->getMessage()];
        }
    }

}

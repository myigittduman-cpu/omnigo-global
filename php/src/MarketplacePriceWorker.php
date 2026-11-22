<?php
declare(strict_types=1);
namespace Omnigo;

/** Runs inside the existing single-worker lock. Mark as checking before any external write. */
final class MarketplacePriceWorker
{
    public function __construct(private Database $db,private MarketplaceStockPilot $pilot,private \Closure $configuration,private ?\Closure $gateways=null){}
    public function tick(int $deadline):bool
    {
        if(!IntegrationImportSchema::tableAvailable($this->db,'marketplace_price_jobs','state'))return false;
        $j=$this->db->one("SELECT * FROM marketplace_price_jobs WHERE state IN ('queued','checking') AND due<=? ORDER BY due,created LIMIT 1",[time()]);
        if(!$j)return false;
        $c=(int)$j['company_id'];$checking=$j['state']==='checking';$attempt=(int)$j['attempts']+1;
        $state=$j['state'];$msg=null;$receipt=$j['receipt'];$delay=min(180,10*2**min($attempt,4));
        try{
            $user=$this->db->one('SELECT role FROM users WHERE id=?',[$j['actor_id']]);
            $conn=$this->db->one('SELECT version FROM records WHERE company_id=? AND kind=?',[$c,'integration_'.$j['provider']]);
            if(!MarketplaceStockJob::allowed($this->db,$this->pilot,$c)||($user['role']??null)!=='admin'||!(new Security($this->db,[]))->active((int)$j['actor_id'])||(int)($conn['version']??-1)!==(int)$j['connection_version'])throw new \DomainException('Yetki veya mağaza bağlantısı değişti.');
            if(!$checking){
                $target=(new ActiveMarketplaceListings($this->db))->writableTarget($c,$j['product_key'],$j['listing_key']);
                if(($target['sku']??null)!==$j['sku']||($target['catalogSku']??$target['remoteId']??null)!==$j['remote_id']||time()-(int)$j['created']>900)throw new \DomainException('İlan veya gönderim süresi yeniden doğrulanmalı.');
            }
            $config=($this->configuration)($c,$j['provider']);$gateway=$this->gateways?($this->gateways)($j['provider']):MarketplaceStockJob::gateway($j['provider']);
            $listing=$gateway->read($config,$j['sku'],$deadline);
            $matches=$j['provider']==='hepsiburada'?HepsiburadaStockIdentity::matchesRead($j,$listing):in_array($j['remote_id'],$listing['remoteIds'],true);
            if(!$matches||$listing['merchantSku']!==$j['sku']||$listing['saleActive']!==true||!is_int($listing['salePriceCents']??null))throw new \DomainException('Aktif ilan ve satış fiyatı doğrulanamadı.');
            if($listing['salePriceCents']===(int)$j['price'])$state='succeeded';
            elseif(!$checking){
                $this->db->run("UPDATE marketplace_price_jobs SET state='checking' WHERE company_id=? AND request_id=? AND listing_key=?",[$c,$j['request_id'],$j['listing_key']]);
                $checking=true;$state='checking';
                $receipt=$gateway->submitPrice($config,$listing,(int)$j['price'],$deadline);
            }elseif($attempt>=10){$state='uncertain';$msg='Fiyat pazaryerinden doğrulanamadı.';}
        }catch(MarketplaceStockFailure $e){
            if($e->retryable&&!$e->uncertain&&$j['state']==='queued'&&$attempt<5){$state='queued';$delay=max($delay,$e->retryAfter);}
            else $state=$checking?($attempt<10?'checking':'uncertain'):'failed';
            $msg='Pazaryeri yanıtı doğrulanamadı.';
        }catch(\Throwable $e){$state=$checking?'uncertain':'failed';$msg=$e instanceof \DomainException?$e->getMessage():'Fiyat işlemi tamamlanamadı.';}
        $this->db->run('UPDATE marketplace_price_jobs SET state=?,due=?,attempts=?,receipt=?,message=? WHERE company_id=? AND request_id=? AND listing_key=?',[$state,time()+$delay,$attempt,$receipt,$msg,$c,$j['request_id'],$j['listing_key']]);
        return true;
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

final class NewRecordWorker
{
    public function __construct(private Database $db,private IntegrationVault $vault,private DeliveryRepository $deliveries) {}

    /** Build the job list from enabled store connections and source capabilities. */
    public static function sources(Database $db,IntegrationVault $vault,int $company): array
    {
        $row=$db->one('SELECT payload FROM company_profiles WHERE company_id=?',[$company]);
        $profile=json_decode($row['payload']??'{}',true,64,JSON_THROW_ON_ERROR);
        $live=$profile['setupOptions']['live']??null;
        $catalog=(new InventorySettings($db))->marketplaceEnabled($company)
            &&($live===null||array_intersect($live,['inventory','fileCenter']));
        $questions=(new InboxRepository($db))->ready()&&($live===null||in_array('inbox',$live,true));
        $finance=PazaramaCommissionStore::available($db);
        $orders=$live===null||(bool)array_intersect($live,['orders','inventory','fileCenter','shipments',
            'deliveries','cityProducts','shipmentInvoices','shipmentDeliveryDocuments','returns']);
        $payouts=$finance&&($live===null||in_array('payments',$live,true));
        $returns=$finance&&($live===null||in_array('returns',$live,true));
        $keys=[];
        foreach((new CustomerMarketplaces($db))->listing($company) as $market){
            if(!$market['selected']||!$market['configured'])continue;
            $provider=$market['provider'];
            $settings=$vault->get($company,$provider);
            if(empty($settings['enabled']))continue;
            if($orders&&($provider!=='hepsiburada'||!empty($settings['ordersEnabled'])))$keys[]='orders:'.$provider;
            if($catalog&&in_array($provider,InventoryCatalogPage::SUPPORTED,true))$keys[]='catalog:'.$provider;
            if($questions&&in_array($provider,NewQuestionRecords::PROVIDERS,true))$keys[]='questions:'.$provider;
            if($payouts&&isset(UpcomingPayoutAdapter::JOBS[$provider]))$keys[]='payouts:'.$provider;
            if($returns&&NewReturnRecords::supported($provider,$settings))$keys[]='returns:'.$provider;
        }
        return $keys;
    }

    public function runOne(int $deadline): bool
    {
        if($deadline-time()<9||!NewRecordRun::available($this->db))return false;
        $store=new NewRecordRun($this->db);
        $lease=$store->claim(time());
        if(!$lease)return false;
        $source=$lease['source'];
        [$type,$provider]=explode(':',$lease['key'],2);
        $finished=false;
        $checkpoint=function(array $result)use($store,$lease,$source,&$finished):void{
            if(isset($source['table'])&&!CustomerSetupSelection::hasLive($this->db,$lease['company'],$source['table']))throw new SyncFailure(403);
            $store->finish($lease,self::advance($source,$result),true);
            $finished=true;
        };
        try{
            $actor=$this->db->one("SELECT u.id FROM users u LEFT JOIN account_controls c ON c.user_id=u.id
                WHERE u.id=? AND u.role='admin' AND COALESCE(c.active,1)=1",[$lease['actor']]);
            $keys=self::sources($this->db,$this->vault,$lease['company']);
            if(isset($source['table'])){
                if(!CustomerSetupSelection::hasLive($this->db,$lease['company'],$source['table']))throw new SyncFailure(403);
                $keys=TableSyncTargets::keys($source['table'],$keys);
            }
            if(!$actor||!in_array($lease['key'],$keys,true)){
                throw new SyncFailure(403);
            }
            $cursor=$source['cursor'];
            if(isset($source['table']))$cursor['target']=['table'=>$source['table'],'mode'=>$source['mode'],'requestedAt'=>$source['requestedAt'],'after'=>$source['after']??0];
            $result=$this->step($lease['company'],$type,$provider,$cursor,$deadline,$checkpoint);
            if($finished)return true;
            $source=self::advance($source,$result);
        }catch(\Throwable $error){
            $this->retry($source,$error);
        }
        $store->finish($lease,$source);
        return true;
    }

    private static function advance(array $source,array $result): array
    {
        $source['state']=$result['done']?'complete':'running';
        $source['added']+=$result['added'];
        $source['cursor']=$result['done']?[]:$result['cursor'];
        $source['nextAt']=time()+max(10,(int)($result['delay']??60));
        $source['attempts']=0;
        $source['error']=null;
        return $source;
    }

    private function step(int $company,string $type,string $provider,array $cursor,int $deadline,callable $checkpoint): array
    {
        $reader=match($type){
            'orders'=>new NewOrderRecords($this->db,$this->vault,$this->deliveries),
            'catalog'=>new NewInventoryRecords($this->db,$this->vault),
            'questions'=>new NewQuestionRecords($this->db,$this->vault),
            'payouts'=>new NewPayoutRecords($this->db,$this->vault),
            'returns'=>new NewReturnRecords($this->db,$this->vault,$this->deliveries),
            default=>throw new \LogicException('Aktarım kaynağı geçersiz.'),
        };
        if($reader instanceof NewOrderRecords){
            $observed=$reader->observed($company,$provider,$cursor,$checkpoint);
            if($observed!==null)return $observed;
        }
        return (new ProviderRequestGate($this->db))->run($company,$provider,$deadline,true,
            fn(int $usableUntil)=>$reader->step($company,$provider,$cursor,$usableUntil,$checkpoint));
    }

    private function retry(array &$source,\Throwable $error): void
    {
        if($error instanceof ProviderRequestDeferred){
            $source['state']='running';
            $source['nextAt']=max(time()+1,$error->retryAt);
            $source['error']='Sıradaki uygun pazaryeri istek aralığı bekleniyor.';
            return;
        }
        $status=$error instanceof SyncFailure?$error->httpStatus:($error instanceof InboxFailure?$error->status:0);
        $retryAfter=$error instanceof SyncFailure||$error instanceof InboxFailure?$error->retryAfter:0;
        if($status===409&&isset($source['cursor']['connection'])){
            // Payout pages can change while being read. Start a clean snapshot on retry.
            $keys=array_fill_keys(['connection','through','ordBaseline'],true);
            $source['cursor']=array_intersect_key($source['cursor'],$keys);
        }
        $attempts=$status===429?$source['attempts']:++$source['attempts'];
        $terminal=in_array($status,[400,401,403,404,410,422],true)||$attempts>=6;
        $source['state']=$terminal?'failed':'running';
        $source['nextAt']=time()+max(60,min(900,30*2**min(5,$attempts)),$retryAfter);
        $source['error']=match(true){
            in_array($status,[401,403],true)=>'Etkin bağlantı veya erişim doğrulanamadı.',
            $terminal=>'Kaynak kontrolü tamamlanamadı.',
            default=>'Kaynak kontrolü yeniden denenecek.',
        };
        if($terminal)$source['cursor']=[];
        if(!$status)error_log('Omnigo yeni kayıt kontrolü: '.get_class($error));
    }
}

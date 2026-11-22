<?php
declare(strict_types=1);
namespace Omnigo;

/** Each connection is saved and queued using its existing transactional setup. */
final class CustomerTransferStart
{
    public function __construct(private Database $db,private string $keyPath) {}

    public function request(array $actor,int $company,array $input): array
    {
        Security::admin($actor);
        $startedAt=time();
        $profileVersion=$input['profileVersion']??null;
        $credentials=$input['connections']??null;
        if($credentials instanceof \stdClass)$credentials=(array)$credentials;
        if(!is_int($profileVersion)||$profileVersion<0||!is_array($credentials)
            ||array_diff_key($credentials,CustomerMarketplaces::PROVIDERS)){
            Http::fail(400,'Aktarım için müşteri ayarlarını ve API bilgilerini kontrol edin.');
        }
        $this->assertProfile($company,$profileVersion);
        $markets=array_column((new CustomerMarketplaces($this->db))->listing($company),null,'provider');
        foreach($credentials as $provider=>$entry){
            if($entry instanceof \stdClass)$credentials[$provider]=$entry=(array)$entry;
            if(!is_array($entry)||!is_int($entry['marketVersion']??null)||!is_int($entry['version']??null)){
                Http::fail(400,'API formunu kontrol edin.');
            }
            $this->assertMarket($markets[$provider],$entry['marketVersion']);
        }

        $results=[];
        foreach($markets as $provider=>$market){
            if(!$market['selected'])continue;
            try{
                $entry=$credentials[$provider]??null;
                $results[$provider]=$entry===null
                    ?$this->ensure($company,$market,$profileVersion)
                    :$this->save($company,$provider,$entry,(int)$actor['id'],$profileVersion);
            }catch(HttpError $error){
                $results[$provider]=['state'=>'error','message'=>$error->getMessage()];
            }catch(\Throwable $error){
                error_log('Omnigo aktarım başlangıcı '.$provider.': '.get_class($error));
                $results[$provider]=['state'=>'error','message'=>'Aktarım sıraya alınamadı. Durumu yenileyip tekrar deneyin.'];
            }
        }
        $eligible=array_filter($results,static fn(array $row):bool=>in_array($row['state'],['scheduled','active'],true));
        $inventory='waiting_connections';$inventoryError='';
        if($eligible&&count($eligible)===count($results)){
            try{$inventory=(new InventoryActivationRequest($this->db))->request($company,(int)$actor['id'],$profileVersion,array_keys($eligible));}
            catch(HttpError $error){$inventory='error';$inventoryError=$error->getMessage();}
            catch(\Throwable $error){
                error_log('Omnigo stok başlangıcı: '.get_class($error));
                $inventory='error';$inventoryError='Stok aktarımı sıraya alınamadı. Son kontrolü yenileyin.';
            }
        }
        $setupRun=StoreSetupRun::begin($this->db,$company,$profileVersion,$results,$startedAt,$this->keyPath);
        return ['companyId'=>$company,'markets'=>(object)$results,'inventory'=>$inventory,'inventoryError'=>$inventoryError,'setupRun'=>$setupRun];
    }

    private function assertMarket(array $market,int $version): void
    {
        if(!$market['selected']||$market['version']!==$version||trim($market['storeName'])===''){
            Http::fail(409,'Mağaza adı veya seçimi değişti. Mağaza bilgilerini yenileyin.');
        }
    }

    private function assertProfile(int $company,int $version): void
    {
        $stored=$this->db->one('SELECT version FROM company_profiles WHERE company_id=?',[$company]);
        if((int)($stored['version']??0)!==$version)Http::fail(409,'Müşteri ayarları değişti. Son kontrolü yenileyin.');
    }

    private function save(int $company,string $provider,array $entry,int $actor,int $version): array
    {
        $vault=new IntegrationVault($this->db,$this->keyPath,function(int $scope,string $key)use($entry,$version):void{
            $this->assertProfile($scope,$version);
            $markets=array_column((new CustomerMarketplaces($this->db))->listing($scope),null,'provider');
            $this->assertMarket($markets[$key],$entry['marketVersion']);
        });
        match($provider){
            'hepsiburada'=>(new HepsiburadaConnection($this->db,$vault))->save($company,$entry,$actor),
            'koctas'=>(new KoctasConnection($this->db,$vault))->save($company,$entry,$actor),
            'n11'=>(new N11Connection($this->db,$vault))->save($company,$entry,$actor),
            'pazarama'=>(new PazaramaConnection($this->db,$vault))->save($company,$entry,$actor),
            default=>(new MarketplaceConnection($this->db,$vault,$provider))->save($company,$entry),
        };
        return ['state'=>'scheduled','message'=>'API kaydedildi. İlk bağlantı kontrolü sıraya alındı.'];
    }

    private function ensure(int $company,array $market,int $version): array
    {
        if(!$market['configured'])return ['state'=>'waiting','message'=>'API bilgileri bekleniyor.'];
        return TransactionRetry::run($this->db,function()use($company,$market,$version){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $this->assertProfile($company,$version);
            $current=array_column((new CustomerMarketplaces($this->db))->listing($company),null,'provider')[$market['provider']];
            $this->assertMarket($current,$market['version']);
            $settings=(new IntegrationVault($this->db,$this->keyPath))->get($company,$market['provider']);
            if(empty($settings['enabled']))return ['state'=>'waiting','message'=>'Etkin API bağlantısı bekleniyor.'];
            $job=$this->db->one('SELECT state,next_run FROM jobs WHERE company_id=? AND provider=?',[$company,$market['name']]);
            if(!$job){
                (new SyncQueue($this->db))->enqueue($company,$market['name']);
                return ['state'=>'scheduled','message'=>'Bağlantı kontrolü sıraya alındı.'];
            }
            if(in_array($job['state'],['blocked','failed'],true))
                return ['state'=>'error','message'=>'Son kontrol başarısız. Bağlantı kontrolünden durumu inceleyin.'];
            return ['state'=>'active','message'=>'Aktarım görevleri kayıtlı. Sıradaki kontrol zamanı korunuyor.'];
        });
    }
}

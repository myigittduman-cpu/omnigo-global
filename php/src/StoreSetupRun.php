<?php
declare(strict_types=1);
namespace Omnigo;

/** One durable, revision-bound first transfer per newly configured store. */
final class StoreSetupRun
{
    public const EVENT='store_setup_completed';
    private const CATALOG=['inventory','fileCenter'];
    private const SERVICES=['inbox'=>'Sorular','returns'=>'İptal ve iade talepleri','payments'=>'Yaklaşan ödemeler'];

    public static function install(Database $db):void
    {
        $text=$db->mysql()?'LONGTEXT':'TEXT';$tail=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run("CREATE TABLE IF NOT EXISTS store_setup_runs (
            company_id INT PRIMARY KEY, signature CHAR(64) NOT NULL, started_at BIGINT NOT NULL,
            checked_at BIGINT NOT NULL, finished_at BIGINT, plan $text NOT NULL, waiting $text NOT NULL,
            FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)".$tail);
    }

    public static function available(Database $db):bool
    {return IntegrationImportSchema::tableAvailable($db,'store_setup_runs','company_id');}

    public static function status(Database $db,int $company):?array
    {
        if(!self::available($db))return null;
        $row=$db->one('SELECT started_at,finished_at,waiting FROM store_setup_runs WHERE company_id=?',[$company]);
        if(!$row)return null;
        return ['state'=>$row['finished_at']===null?'waiting':'complete','startedAt'=>gmdate('c',(int)$row['started_at']),
            'finishedAt'=>$row['finished_at']===null?null:gmdate('c',(int)$row['finished_at']),
            'waiting'=>json_decode($row['waiting'],true,32,JSON_THROW_ON_ERROR)];
    }

    public static function begin(Database $db,int $company,int $profileVersion,array $markets,int $started,string $keyPath):?array
    {
        if(!self::available($db)||!IntegrationImportSchema::available($db))return null;
        TransactionRetry::run($db,function()use($db,$company,$profileVersion,$markets,$started,$keyPath){
            $db->one('SELECT id FROM companies WHERE id=?'.$db->lock(),[$company]);
            $stored=$db->one('SELECT payload,version FROM company_profiles WHERE company_id=?',[$company]);
            if(!$stored||(int)$stored['version']!==$profileVersion)return;
            $profile=json_decode($stored['payload'],true,64,JSON_THROW_ON_ERROR);
            $live=$profile['setupOptions']['live']??null;
            if(!$live||!$markets)return;
            $previous=$db->one('SELECT signature,started_at,finished_at,plan FROM store_setup_runs WHERE company_id=?'.$db->lock(),[$company]);
            if($previous&&$previous['finished_at']!==null)return;
            $expected=[];$vault=new IntegrationVault($db,$keyPath);
            foreach($markets as $provider=>$result){
                $snapshot=self::credential($db,$company,$provider);
                $accepted=in_array($result['state'],['scheduled','active'],true);
                $revision=$accepted?($vault->get($company,$provider)['revision']??null):null;
                $expected[$provider]=['id'=>(int)($snapshot['id']??0),'version'=>$accepted?(int)($snapshot['version']??0):0,
                    'revision'=>$revision,
                    'updated'=>$snapshot['updated']??null];
            }
            $plan=['live'=>$live,'providers'=>$expected,'mode'=>$profile['workspacePolicy']['inventory']['mode']??'marketplace'];
            $signature=hash('sha256',json_encode($plan,JSON_THROW_ON_ERROR));
            if($previous&&hash_equals($previous['signature'],$signature))return;
            $prior=$previous?json_decode($previous['plan'],true,32,JSON_THROW_ON_ERROR):[];
            foreach($plan['providers'] as $provider=>&$connection){
                $old=$prior['providers'][$provider]??[];
                $sameConnection=$connection['version']>0&&$connection['version']===($old['version']??0)
                    &&$connection['id']===($old['id']??0)&&$connection['revision']===($old['revision']??null);
                $connection['ordersSince']=$sameConnection?($old['ordersSince']??(int)$previous['started_at']):$started;
                $sameCatalog=$sameConnection&&$plan['mode']===$prior['mode']&&array_intersect(self::CATALOG,$prior['live']);
                $connection['catalogSince']=$sameCatalog?($old['catalogSince']??(int)$previous['started_at']):$started;
                if($sameCatalog)$connection+=array_intersect_key($old,['catalogStart'=>true,'catalogComplete'=>true]);
                foreach(array_keys(self::SERVICES) as $scope){
                    $sameService=$sameConnection&&in_array($scope,$prior['live'],true);
                    $connection['serviceSince'][$scope]=$sameService?($old['serviceSince'][$scope]??(int)$previous['started_at']):$started;
                }
            }
            unset($connection);
            $json=json_encode($plan,JSON_THROW_ON_ERROR);
            if($previous)$db->run('UPDATE store_setup_runs SET signature=?,started_at=?,checked_at=0,plan=?,waiting=? WHERE company_id=?',
                [$signature,$started,$json,'[]',$company]);
            else $db->run('INSERT INTO store_setup_runs(company_id,signature,started_at,checked_at,plan,waiting) VALUES(?,?,?,0,?,?)',
                [$company,$signature,$started,$json,'[]']);
        });
        return self::status($db,$company);
    }

    public static function check(Database $db,?int $company=null):void
    {
        if(!self::available($db)||!IntegrationImportSchema::available($db))return;
        $rows=$db->all('SELECT company_id FROM store_setup_runs WHERE finished_at IS NULL'.($company===null?'':' AND company_id=?').' ORDER BY checked_at,company_id LIMIT 20',
            $company===null?[]:[$company]);
        foreach($rows as $row){
            try{TransactionRetry::run($db,fn()=>self::observe($db,(int)$row['company_id']));}
            catch(\Throwable $error){
                if($company!==null)throw $error;
                error_log('Omnigo kurulum aktarımı: '.get_class($error));
            }
        }
    }

    private static function observe(Database $db,int $company):void
    {
        $db->one('SELECT id FROM companies WHERE id=?'.$db->lock(),[$company]);
        $row=$db->one('SELECT * FROM store_setup_runs WHERE company_id=?'.$db->lock(),[$company]);
        if(!$row||$row['finished_at']!==null)return;
        $plan=json_decode($row['plan'],true,32,JSON_THROW_ON_ERROR);
        $started=(int)$row['started_at'];
        $profile=CustomerSetupSelection::profile($db,$company);
        $selected=array_keys($plan['providers']);
        $waiting=[];
        if($plan['live']!==($profile['setupOptions']['live']??null)
            ||$plan['mode']!==($profile['workspacePolicy']['inventory']['mode']??'marketplace')
            ||$selected!==(new InventoryActivationRequest($db))->selectedProviders($company)){
            $waiting[]='Mağaza çalışma kapsamı değişti. Son kontrolden aktarımı yeniden başlatın.';
        }else foreach($plan['providers'] as $provider=>$expected){
            $label=CustomerMarketplaces::PROVIDERS[$provider];
            $current=self::credential($db,$company,$provider);
            if(!self::matchesCredential($current,$expected)){
                $waiting[]=$label.' · Bağlantının yeniden başlatılması bekleniyor.';continue;
            }
            $run=$db->one('SELECT state,started_at,finished_at,credential_revision,baseline FROM integration_import_runs
                WHERE company_id=? AND provider=? AND credential_version=?',[$company,$provider,$expected['version']]);
            if(!$run||$run['credential_revision']!==$expected['revision']||$run['state']!=='complete'
                ||strtotime((string)$run['finished_at'])<($expected['ordersSince']??$started)){
                $waiting[]=$label.' · İlk tam sipariş aktarımı bekleniyor.';
            }
            if(array_intersect(self::CATALOG,$plan['live'])){
                if($plan['mode']==='store')$waiting[]=$label.' · Canlı ürün kataloğu için stok çalışma modelini kontrol edin.';
                elseif(!self::catalogComplete($db,$company,$provider,$run,$expected,$started)){
                    $waiting[]=$label.' · Doğrulanmış tam ürün ve stok taraması bekleniyor.';
                }
            }
            foreach(self::SERVICES as $scope=>$service){
                if(!in_array($scope,$plan['live'],true))continue;
                // Manual-only payouts and PTT AVM's absent questions service have no automatic run to verify.
                if(($scope==='payments'&&!isset(UpcomingPayoutAdapter::JOBS[$provider]))||($scope==='inbox'&&$provider==='pttavm'))continue;
                $since=$expected['serviceSince'][$scope]??$started;
                if($scope==='payments'&&self::payoutComplete($db,$company,$provider,$since))continue;
                if($scope!=='payments'&&StoreSetupServiceEvidence::complete($db,$company,$provider,$scope,$since,$expected['revision']))continue;
                $supported=$scope==='payments'||StoreSetupServiceEvidence::supported($provider,$scope);
                $waiting[]=$label.' · '.$service.($supported?' aktarımı bekleniyor.':' için bu kurulumda doğrulanmış tam aktarım kanıtı yok.');
            }
        }
        $now=time();
        $db->run('UPDATE store_setup_runs SET checked_at=?,waiting=?,finished_at=? WHERE company_id=?',
            [$now,json_encode($waiting,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$waiting?null:$now,$company]);
        if(!$waiting)self::notify($db,$company,$now);
    }

    private static function catalogComplete(Database $db,int $company,string $provider,?array $run,array $expected,int $started):bool
    {
        if(!$run||$run['credential_revision']!==$expected['revision'])return false;
        if(!empty($expected['catalogComplete']))return true;
        $progress=$db->one('SELECT * FROM inventory_catalog_progress WHERE company_id=? AND provider=?',[$company,$provider]);
        return (int)($progress['started_at']??0)>=($expected['catalogSince']??$started)&&IntegrationImportEvidence::catalog($run,$progress);
    }

    /** Recorded only after a catalogue worker commits the fenced page under the live credential. */
    public static function catalogPage(Database $db,int $company,array $job,string $revision):void
    {
        if($revision===''||!self::available($db))return;
        TransactionRetry::run($db,function()use($db,$company,$job,$revision){
            $db->one('SELECT id FROM companies WHERE id=?'.$db->lock(),[$company]);
            $row=$db->one('SELECT started_at,plan FROM store_setup_runs WHERE company_id=? AND finished_at IS NULL'.$db->lock(),[$company]);
            if(!$row)return;
            $plan=json_decode($row['plan'],true,32,JSON_THROW_ON_ERROR);$provider=$job['provider'];
            $expected=$plan['providers'][$provider]??null;
            if(!$expected||!$expected['version']||!array_intersect(self::CATALOG,$plan['live']))return;
            $current=self::credential($db,$company,$provider);
            $run=$db->one('SELECT credential_revision FROM integration_import_runs WHERE company_id=? AND provider=? AND credential_version=?',
                [$company,$provider,$expected['version']]);
            if(!self::matchesCredential($current,$expected)||$expected['revision']!==$revision||($run['credential_revision']??null)!==$revision)return;
            $progress=$db->one('SELECT started_at,completed_at,state,unknown_quantities FROM inventory_catalog_progress WHERE company_id=? AND provider=?',[$company,$provider]);
            $started=(int)($progress['started_at']??0);
            if($started<($expected['catalogSince']??(int)$row['started_at']))return;
            if((int)$job['page_no']===0&&(int)$job['partition_no']===0)$expected['catalogStart']=$started;
            if(($expected['catalogStart']??0)!==$started)return;
            if($progress['state']==='complete'&&(int)$progress['unknown_quantities']===0)$expected['catalogComplete']=(int)$progress['completed_at'];
            $plan['providers'][$provider]=$expected;
            $db->run('UPDATE store_setup_runs SET plan=? WHERE company_id=?',[json_encode($plan,JSON_THROW_ON_ERROR),$company]);
        });
    }

    private static function credential(Database $db,int $company,string $provider):?array
    {return $db->one('SELECT id,version,updated FROM records WHERE company_id=? AND kind=?',[$company,'integration_'.$provider]);}

    private static function matchesCredential(?array $current,array $expected):bool
    {
        return $current&&$expected['version']>0&&!empty($expected['revision'])
            &&(int)$current['id']===($expected['id']??0)&&(int)$current['version']===$expected['version']
            &&$current['updated']===$expected['updated'];
    }

    private static function payoutComplete(Database $db,int $company,string $provider,int $started):bool
    {
        if(!isset(UpcomingPayoutAdapter::JOBS[$provider]))return false;
        $name=UpcomingPayoutAdapter::JOBS[$provider];
        $job=$db->one('SELECT state,next_run,attempts FROM jobs WHERE company_id=? AND provider=?',[$company,$name]);
        $state=(new MarketplaceServiceHealth($db))->payout($company,$provider,[$name=>$job],true,true);
        return $state['status']==='ready'&&strtotime((string)$state['lastSuccessAt'])>=$started;
    }

    public static function isNotice(array $detail):bool
    {return is_array($detail['events']??null)&&in_array(self::EVENT,$detail['events'],true);}

    public static function presentation():array
    {return ['title'=>'Mağaza kurulumu tamamlandı','body'=>'Kurulumda seçilen canlı veri aktarımları doğrulandı.','view'=>'overview'];}

    private static function notify(Database $db,int $company,int $timestamp):void
    {
        foreach(['admin','client'] as $audience){
            $key=hash('sha256',self::EVENT.':'.$company.':'.$audience);
            $insert=$db->mysql()?'INSERT IGNORE':'INSERT OR IGNORE';
            $payload=json_encode(['audience'=>$audience,'events'=>[self::EVENT],'section'=>'overview'],JSON_THROW_ON_ERROR);
            $row=$db->run("$insert INTO order_notifications(company_id,provider,order_key,event_type,payload,created) VALUES(?,?,?,?,?,?)",
                [$company,'portal',$key,'operation',$payload,gmdate('c',$timestamp)]);
            if($row->rowCount()===1){
                try{PushRoutes::devices($db)->enqueue($db->insertId(),$audience==='client'?$company:null);}
                catch(\Throwable $error){error_log('Omnigo kurulum bildirimi: '.get_class($error));}
            }
        }
    }
}

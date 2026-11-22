<?php
declare(strict_types=1);
namespace Omnigo;

final class IntegrationRoutes
{
    public function __construct(private Database $db,private array $config,private Security $security) {}
    public function handle(string $path,string $method,array $user): void
    {
        if ($path==='/api/integrations/koctas/check') {
            Security::admin($user);
            if ($method!=='GET') Http::fail(405,'İşlem desteklenmiyor.');
            $company=$this->security->company($user,$_GET['company']??null);
            Http::json((new KoctasDiagnostics($this->db))->report($company,Validation::month($_GET['month']??null)));
        }
        if ($path!=='/api/integrations/koctas') return;
        Security::admin($user); $company=$this->security->company($user,$_GET['company']??null);
        $vault=new IntegrationVault($this->db,dirname(__DIR__).'/storage/backup.key');
        $connection=new KoctasConnection($this->db,$vault);
        if($method==='GET')Http::json($connection->status($company));
        if($method==='POST'){
            $data=Http::body();
            if(($data['action']??'')==='retry')$connection->retry($company);
            else $connection->save($company,$data,(int)$user['id']);
            Http::json(['message'=>'Koçtaş bağlantı kontrolü ve aktarımı sıraya alındı.']);
        }
        Http::fail(405,'İşlem desteklenmiyor.');
    }
    public static function channels(Database $db,int $company,array $channels): array
    {
        $configured=$db->one("SELECT id FROM records WHERE company_id=? AND kind='integration_koctas'",[$company]);
        $row=$db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_koctas'",[$company]);
        $state=$row?json_decode($row['payload'],true):[];
        $job=$db->one("SELECT state,error FROM jobs WHERE company_id=? AND provider='Koçtaş'",[$company]);
        foreach($channels as &$channel) if ($channel['name']==='Koçtaş' && $configured) {
            $channel['connection']=in_array($job['state']??'',['failed','blocked'],true)?'error':(!empty($state['lastSuccessAt'])?'connected':'syncing');
            $channel['lastSyncAt']=$state['lastSuccessAt']??null; $channel['connectionError']=$job['error']??null;
        }
        unset($channel);
        $pazarama=$db->one("SELECT id FROM records WHERE company_id=? AND kind='integration_pazarama'",[$company]);
        if ($pazarama) {
            $probe=$db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_pazarama'",[$company]);
            $phase=$probe?json_decode($probe['payload'],true):[];
            $job=$db->one("SELECT state,error FROM jobs WHERE company_id=? AND provider='Pazarama'",[$company]);
            foreach($channels as &$channel) if ($channel['name']==='Pazarama') {
                $channel['connection']=in_array($job['state']??'',['blocked','failed'],true)?'error':(!empty($phase['lastSuccessAt'])?'connected':'syncing');
                $channel['connectionError']=$job['error']??null;
                $channel['lastSyncAt']=$phase['lastSuccessAt']??null;
            }
            unset($channel);
        }
        $n11=$db->one("SELECT id FROM records WHERE company_id=? AND kind='integration_n11'",[$company]);
        if($n11){
            $row=$db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_n11'",[$company]);
            $sync=$row?json_decode($row['payload'],true):[];
            $job=$db->one("SELECT state,error FROM jobs WHERE company_id=? AND provider='n11'",[$company]);
            foreach($channels as &$channel)if($channel['name']==='n11'){
                $channel['connection']=in_array($job['state']??'',['failed','blocked'],true)?'error':(!empty($sync['lastSuccessAt'])?'connected':(($sync['phase']??'')==='review_ready'?'review':'syncing'));
                $channel['lastSyncAt']=$sync['lastSuccessAt']??null;
                $channel['connectionError']=$job['error']??null;
            }
            unset($channel);
        }
        $hb=$db->one("SELECT id FROM records WHERE company_id=? AND kind='integration_hepsiburada'",[$company]);
        if($hb){
            $row=$db->one("SELECT payload FROM records WHERE company_id=? AND kind='sync_hepsiburada'",[$company]);
            $sync=$row?json_decode($row['payload'],true):[];
            $job=$db->one("SELECT state,error FROM jobs WHERE company_id=? AND provider='Hepsiburada'",[$company]);
            foreach($channels as &$channel)if($channel['name']==='Hepsiburada'){
                $channel['connection']=in_array($job['state']??'',['failed','blocked'],true)?'error':(!empty($sync['ordersPublished'])?'connected':(($sync['phase']??'')==='review_ready'?'review':'checking'));
                $channel['connectionError']=$job['error']??null;
                $channel['lastSyncAt']=$sync['financial']['through']??$sync['lastSuccessAt']??null;
            }
            unset($channel);
        }
        foreach(MarketplaceConnection::PROVIDERS as $provider=>$name){
            $connection=new MarketplaceConnection($db,new IntegrationVault($db,dirname(__DIR__).'/storage/backup.key'),$provider);
            $status=$connection->status($company);if(!$status['configured'])continue;
            foreach($channels as &$channel)if($channel['name']===$name){
                $channel['connection']=in_array($status['job']['state']??'',['failed','blocked'],true)?'error':(!empty($status['sync']['ordersPublished'])?'connected':(($status['sync']['phase']??'')==='verified'?'review':'checking'));
                $channel['connectionError']=$status['job']['error']??null;
                $channel['lastSyncAt']=$status['sync']['lastSuccessAt']??null;
            }unset($channel);
        }
        return $channels;
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;
final class MarketplaceConnectionRoutes
{
    public function __construct(private Database $db,private Security $security){}
    public function handle(string $path,string $method,array $user): void
    {
        if(preg_match('#^/api/integrations/(hepsiburada|n11|pazarama|koctas|pttavm|idefix|trendyol)/credentials$#D',$path,$match)){
            Security::admin($user);
            if($method!=='POST')Http::fail(405,'İşlem desteklenmiyor.');
            $this->security->csrf($user);
            $view=new IntegrationCredentialView($this->db,$this->security,new IntegrationVault($this->db,dirname(__DIR__).'/storage/backup.key'));
            Http::json($view->read($user,$_GET['company']??null,$match[1]));
        }
        if(!preg_match('#^/api/integrations/(pttavm|idefix|trendyol)$#D',$path,$match))return;
        Security::admin($user);$company=$this->security->company($user,$_GET['company']??null);
        $connection=new MarketplaceConnection($this->db,new IntegrationVault($this->db,dirname(__DIR__).'/storage/backup.key'),$match[1]);
        if($method==='GET')Http::json($connection->status($company));
        if($method==='POST'){
            $this->security->consumeAttempt(hash('sha256','marketplace-connection:'.$match[1].':'.$company));$input=Http::body();
            if(($input['action']??'')==='retry')$connection->retry($company);else $connection->save($company,$input);
            Http::json(['message'=>'Bağlantı kontrolü sıraya alındı.'],202);
        }
        Http::fail(405,'İşlem desteklenmiyor.');
    }
}

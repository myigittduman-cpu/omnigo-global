<?php
declare(strict_types=1);
namespace Omnigo;

final class MarketplaceGateRoutes
{
    public function __construct(private Database $db,private Security $security){}
    public function handle(string $path,string $method,array $user):void
    {
        if(!in_array($path,['/api/admin/marketplace-access','/api/admin/workspace-access'],true))return;
        Security::admin($user);$company=(string)($_GET['company']??'')==='0'?0:$this->security->company($user,$_GET['company']??null);$gate=new MarketplaceGate($this->db);
        if($path==='/api/admin/workspace-access'){
            if($method!=='GET')Http::fail(405,'Müşteri geçiş doğrulaması yalnız okunabilir.');
            Http::json($gate->workspaceAccess($user,$company));
        }
        if($method==='GET')Http::json($gate->status($user,$company));
        if($method==='POST'){
            $this->security->consumeAttempt(hash('sha256','marketplace-gate:'.$user['id'].':'.($_SERVER['REMOTE_ADDR']??'')));
            Http::json($gate->unlock($user,$company,Http::body()));
        }
        if($method==='DELETE'){$gate->revoke($user,$company,(string)(Http::body()['grant']??''));Http::json(['locked'=>true]);}
        Http::fail(405,'Doğrulama işlemi desteklenmiyor.');
    }
}

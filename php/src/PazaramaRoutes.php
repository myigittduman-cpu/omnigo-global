<?php
declare(strict_types=1);
namespace Omnigo;

final class PazaramaRoutes
{
    public function __construct(private Database $db,private Security $security) {}
    public function handle(string $path,string $method,array $user): void
    {
        if ($path!=='/api/integrations/pazarama') return;
        Security::admin($user);
        $company=$this->security->company($user,$_GET['company']??null);
        $vault=new IntegrationVault($this->db,dirname(__DIR__).'/storage/backup.key');
        $connection=new PazaramaConnection($this->db,$vault);
        $importer=new PazaramaHistoryImporter($this->db,new DeliveryCipher(dirname(__DIR__).'/storage/backup.key'));
        $history=new PazaramaOrderHistory($this->db,$importer);
        if ($method==='GET'){
            $status=$connection->status($company);
            Http::json($status+['history'=>$status['configured']?$history->status($company,$status['sync']['startDate']??null):null]);
        }
        if ($method==='POST') {
            $this->security->consumeAttempt(hash('sha256','pazarama-connection:'.$company));
            $input=Http::body();
            if(($input['action']??'')==='extendHistory'){
                $history->request($company,$input,(int)$user['id']);
                Http::json(['queued'=>true,'message'=>'Pazarama arşivindeki eksik siparişler için istek alındı.'],202);
            }
            if (($input['action']??'')==='retry') $connection->retry($company);
            else $connection->save($company,$input,(int)$user['id']);
            Http::json(['message'=>'Okuma kontrolü sıraya alındı. Sonuç bu ekranda gösterilecek.'],202);
        }
        Http::fail(405,'İşlem desteklenmiyor.');
    }
}

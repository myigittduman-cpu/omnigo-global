<?php
declare(strict_types=1);
namespace Omnigo;

final class HepsiburadaRoutes
{
    public function __construct(private Database $db,private Security $security) {}
    public function handle(string $path,string $method,array $user): void
    {
        if ($path!=='/api/integrations/hepsiburada') return;
        Security::admin($user);
        $company=$this->security->company($user,$_GET['company']??null);
        $connection=new HepsiburadaConnection($this->db,new IntegrationVault($this->db,dirname(__DIR__).'/storage/backup.key'));
        if ($method==='GET') Http::json($connection->status($company));
        if ($method==='POST') {
            $this->security->consumeAttempt(hash('sha256','hepsiburada-connection:'.$company));
            $input=Http::body();
            if (($input['action']??'')==='retry') $connection->retry($company);
            else $connection->save($company,$input,(int)$user['id']);
            Http::json(['message'=>'Okuma kontrolü sıraya alındı. Sonuç bu ekranda gösterilecek.'],202);
        }
        Http::fail(405,'İşlem desteklenmiyor.');
    }
}

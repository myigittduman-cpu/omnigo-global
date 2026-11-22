<?php
declare(strict_types=1);
namespace Omnigo;

final class AccountActivityRoutes
{
    public function __construct(private Database $db){}
    public function handle(string $path,string $method,array $actor):void
    {
        if($path==='/api/account/activity'&&$method==='GET')Http::json((new AdministrationHistory($this->db))->listing($actor,$_GET));
        if(!in_array($path,['/api/account/sessions','/api/account/sessions/revoke'],true))return;
        $sessions=new SessionDirectory($this->db,dirname(__DIR__).'/storage/backup.key');
        if($path==='/api/account/sessions'&&$method==='GET')Http::json($sessions->listing($actor,$_GET));
        if($path==='/api/account/sessions/revoke'&&$method==='POST'){$sessions->revoke($actor,Http::body());Http::json(['revoked'=>true]);}
        Http::fail(405,'İşlem desteklenmiyor.');
    }
}

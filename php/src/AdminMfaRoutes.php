<?php
declare(strict_types=1);
namespace Omnigo;

final class AdminMfaRoutes
{
    public function __construct(private Database $db,private array $config,private Security $security){}
    public function handle(string $path,string $method,array $user): void
    {
        if(!str_starts_with($path,'/api/account/security'))return;
        Security::admin($user);PortalAccess::assertRole($this->config,'admin');
        $mfa=new AdminEmailMfa($this->db,$this->config);
        if($path==='/api/account/security'&&$method==='GET')Http::json($mfa->status($user));
        if($path==='/api/account/security/email/request'&&$method==='POST')Http::json($mfa->requestSetting($user,Http::body()));
        if($path==='/api/account/security/email/verify'&&$method==='POST')Http::json($this->security->confirmMfa(Http::body(),$user));
        if($path==='/api/account/security/email/resend'&&$method==='POST')Http::json($mfa->resend(Http::body(),$user));
        Http::fail(405,'Güvenlik işlemi desteklenmiyor.');
    }
}

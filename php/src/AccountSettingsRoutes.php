<?php
declare(strict_types=1);
namespace Omnigo;
final class AccountSettingsRoutes
{
    public function __construct(private Database $db,private array $config){}
    public function handle(string $path,string $method,array $user): void
    {
        if($path==='/api/account/operation-mail'&&in_array($method,['GET','PUT'],true)){
            if(!AdminLoginProfiles::canManageApplication($this->db,$user))Http::fail(403,'Bu alan için erişim yetkiniz yok.');
            $mail=new OperationMailPreference($this->db);
            if($method==='PUT'){$data=Http::body();Http::json($mail->save($data['enabled']??null,$data['version']??null));}
            Http::json($mail->read());
        }
        if($path==='/api/account/username'&&$method==='GET')Http::json(['username'=>LoginIdentity::read($this->db,(int)$user['id'])]);
        if($path==='/api/account/username'&&$method==='PUT')Http::json(LoginIdentity::changeOwn($this->db,$user,Http::body()));
        if($path==='/api/account/session'&&$method==='GET')Http::json((new Security($this->db,$this->config))->sessionPreference($user));
        if($path==='/api/account/session'&&$method==='PUT')Http::json((new Security($this->db,$this->config))->sessionPreference($user,Http::body()));
        if($path==='/api/account/preferences'&&$method==='GET')Http::json([...(new UserPreferences($this->db))->read($user),'canManageApplication'=>AdminLoginProfiles::canManageApplication($this->db,$user),'workspaceDesign'=>WorkspaceDesign::shared()->read()]);
        if($path==='/api/account/workspace-design'&&!AdminLoginProfiles::canManageApplication($this->db,$user))Http::fail(403,'Bu alan için erişim yetkiniz yok.');
        if($path==='/api/account/workspace-design'&&$method==='GET')Http::json(['workspaceDesign'=>WorkspaceDesign::shared()->read()]);
        if($path==='/api/account/workspace-design'&&$method==='PUT')Http::json(['workspaceDesign'=>WorkspaceDesign::shared()->save($user,Http::body())]);
        if($path==='/api/account/preferences'&&$method==='PUT')Http::json([...(new UserPreferences($this->db))->save($user,Http::body()),'canManageApplication'=>AdminLoginProfiles::canManageApplication($this->db,$user)]);
        if($method!=='POST')return;
        if($path==='/api/account/password/request')Http::json((new PasswordChange($this->db,$this->config))->request($user,Http::body()),202);
        if($path==='/api/account/password/resend')Http::json((new PasswordChange($this->db,$this->config))->resend($user,Http::body()),202);
        if($path==='/api/account/password/verify'){
            (new PasswordChange($this->db,$this->config))->verify($user,Http::body());Http::json(['changed'=>true]);
        }
    }
}

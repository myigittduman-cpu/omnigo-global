<?php
declare(strict_types=1);
namespace Omnigo;

/** Role changes serialize on install_state, revoke sessions and retain business authorship. */
final class AccountDirectoryWriter
{
    private AccountDirectory $directory;
    public function __construct(private Database $db){$this->directory=new AccountDirectory($db);}
    private function lock():void{$this->db->one('SELECT id FROM install_state WHERE id=1'.$this->db->lock());}
    private function company(string $role,array $data):?int
    {
        if($role==='admin')return null;
        $id=filter_var($data['companyId']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id||!$this->db->one('SELECT id FROM companies WHERE id=?',[$id]))Http::fail(400,'Müşteri hesabı için mağaza seçin.');
        return $id;
    }
    public function save(array $actor,?int $id,array $data):array
    {
        Security::admin($actor);
        $role=Validation::choice($data['role']??null,['admin','client']);$company=$this->company($role,$data);
        $storeRole=$role==='client'&&$id===null?CustomerAccountRoles::forCreation($data['storeRole']??null):null;
        $name=Validation::text($data['name']??null,'Ad soyad',100);$email=mb_strtolower(Validation::text($data['email']??null,'E-posta',200));
        $username=LoginIdentity::validate($data['username']??null);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!is_bool($data['active']??null))Http::fail(400,'Hesap bilgilerini kontrol edin.');
        $password=$data['password']??'';if(!is_string($password))Http::fail(400,'Şifreyi kontrol edin.');
        if(!$id||$password!=='')$password=Validation::account(['name'=>$name,'email'=>$email,'password'=>$password],$role==='client')['password'];
        $hash=$password!==''?Security::hashPassword($password):null;
        return TransactionRetry::run($this->db,function()use($actor,$id,$data,$role,$company,$name,$email,$username,$hash,$storeRole){
            $this->lock();$before=$id?$this->directory->row($id):null;
            if($before)$this->directory->assertRevision($before,$data['revision']??null);
            if($before&&$before['role']==='client'&&$role==='admin'&&!$hash)
                Http::fail(400,'Omnigo yöneticisi için yeni bir yönetici şifresi belirleyin.');
            if($before&&(int)$actor['id']===$id&&($role!=='admin'||!$data['active']))Http::fail(400,'Kendi yönetici yetkinizi veya hesabınızı bu ekrandan kapatamazsınız.');
            if($before&&$before['role']==='admin'&&($role!=='admin'||!$data['active']))$this->requireAnotherAdmin($id);
            if($this->db->one('SELECT id FROM users WHERE email=? AND id<>?',[$email,$id??0]))Http::fail(409,'E-posta başka bir hesapta kullanılıyor.');
            if(!$id){
                if($company)$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                $this->db->run('INSERT INTO users(email,name,password,role,company_id) VALUES(?,?,?,?,?)',[$email,$name,$hash,$role,$company]);$id=$this->db->insertId();
                $this->db->run('INSERT INTO account_profiles(user_id) VALUES(?)',[$id]);
                $this->directory->assign($id,null,$company,$storeRole);
            }else{
                $from=$before['company_id']===null?null:(int)$before['company_id'];$companies=array_unique(array_filter([$from,$company]));sort($companies);
                foreach($companies as $tenant)$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$tenant]);
                $this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$id]);$this->directory->assertRevision($this->directory->row($id),$data['revision']??null);
                $this->directory->assign($id,$from,$company);
                $this->db->run('UPDATE users SET email=?,name=?,role=?,company_id=? WHERE id=?',[$email,$name,$role,$company,$id]);
                if($hash)$this->db->run('UPDATE users SET password=? WHERE id=?',[$hash,$id]);
            }
            LoginIdentity::save($this->db,$id,$username);
            $this->db->run('DELETE FROM account_controls WHERE user_id=?',[$id]);$this->db->run('INSERT INTO account_controls(user_id,active) VALUES(?,?)',[$id,$data['active']?1:0]);
            if($hash&&$before)StoreAccountCredentials::clear($this->db,$id);
            if($before&&($hash||!$data['active']||$before['email']!==$email||$before['username']!==$username||$before['role']!==$role||($before['company_id']===null?null:(int)$before['company_id'])!==$company))$this->directory->revoke($id);
            if($before&&$before['role']!==$role)$this->db->run('DELETE FROM admin_mfa_accounts WHERE user_id=?',[$id]);
            $changes=[];
            if($before){foreach(['name'=>[$name,'Ad soyad'],'email'=>[$email,'E-posta'],'username'=>[$username,'Kullanıcı adı'],'role'=>[$role,'Rol']] as $field=>[$value,$label])if($before[$field]!==$value)$changes[]=$label;if((bool)$before['active']!==$data['active'])$changes[]='Hesap durumu';if(($before['company_id']===null?null:(int)$before['company_id'])!==$company)$changes[]='Bağlı mağaza';if($hash)$changes[]='Şifre';}
            $this->directory->audit($actor,$id,$before?($changes?'Kullanıcı güncellendi: '.implode(', ',$changes):'Kullanıcı bilgileri kaydedildi'):'Kullanıcı oluşturuldu');
            return AccountDirectory::publicRow($this->directory->row($id));
        });
    }
    private function requireAnotherAdmin(int $id):void
    {
        if(!$this->db->one("SELECT u.id FROM users u LEFT JOIN account_controls c ON c.user_id=u.id WHERE u.role='admin' AND u.id<>? AND COALESCE(c.active,1)=1",[$id]))Http::fail(409,'Son aktif yönetici kaldırılamaz.');
    }
    public function remove(array $actor,int $id,array $data):void
    {
        Security::admin($actor);
        TransactionRetry::run($this->db,function()use($actor,$id,$data){
            $this->lock();$row=$this->directory->row($id);
            if($row['company_id']!==null)$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$row['company_id']]);
            $this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$id]);$row=$this->directory->row($id);$this->directory->assertRevision($row,$data['revision']??null);
            if((int)$actor['id']===$id)Http::fail(400,'Açık oturumunuzun hesabını bu ekrandan silemezsiniz.');
            if($row['role']==='admin')$this->requireAnotherAdmin($id);
            $this->directory->revoke($id);$this->directory->assign($id,$row['company_id']===null?null:(int)$row['company_id'],null);
            foreach(['user_login_names','user_preferences','admin_mfa_accounts','account_controls'] as $table)$this->db->run("DELETE FROM $table WHERE user_id=?",[$id]);
            $this->db->run('INSERT INTO account_controls(user_id,active) VALUES(?,0)',[$id]);
            $this->db->run('UPDATE users SET email=?,password=? WHERE id=?',['removed-'.$id.'-'.bin2hex(random_bytes(8)).'@accounts.invalid',Security::hashPassword(bin2hex(random_bytes(32))),$id]);
            $this->db->run('DELETE FROM account_profiles WHERE user_id=?',[$id]);$this->db->run('INSERT INTO account_profiles(user_id,deleted_at) VALUES(?,?)',[$id,time()]);
            $this->directory->audit($actor,$id,'Giriş hesabı silindi; geçmiş işlem kimliği korundu');
        });
    }
}

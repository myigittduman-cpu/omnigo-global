<?php
declare(strict_types=1);
namespace Omnigo;

final class CustomerManagement
{
    public function __construct(private Database $db) {}
    public function profile(int $company, bool $admin): array
    {
        $row = $this->db->one('SELECT * FROM company_profiles WHERE company_id=?',[$company]);
        $value = $row ? json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR) : [];
        return $admin ? [...$value,'shippingScope'=>CustomerShippingScope::read($value),'version'=>(int)($row['version']??0),'hasLogo'=>!empty($row['logo'])] : ['hasLogo'=>!empty($row['logo']),'brandingVersion'=>(int)($row['version']??0)];
    }
    public function audit(array $actor, int $company, string $action, int $id): void
    {
        $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,created) VALUES(?,?,?,?,?)',[$actor['id'],$company,$action,$id,gmdate('c')]);
    }
    public static function profileFields(array $data): array
    {
        $value=[];
        foreach (['contactName'=>100,'phone'=>40,'email'=>200,'address'=>500,'notes'=>3000] as $key=>$max) $value[$key]=Validation::text($data[$key]??'',$key,$max,true);
        if ($value['email']!=='' && !filter_var($value['email'],FILTER_VALIDATE_EMAIL)) Http::fail(400,'Geçerli bir e-posta yazın.');
        return $value;
    }
    public function saveProfile(array $actor, int $company, array $data): void
    {
        $name = Validation::text($data['name']??null,'Firma adı',150);
        $value=self::profileFields($data);
        $this->db->transaction(function() use($actor,$company,$data,$name,$value) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $existing=$this->profile($company,true);
            if (($data['version']??null)!==$existing['version']) Http::fail(409,'Bilgiler değişmiş. Pencereyi yeniden açın.');
            if(isset($existing['initialPasswordSequence']))$value['initialPasswordSequence']=$existing['initialPasswordSequence'];
            if(isset($existing['modules']))$value['modules']=$existing['modules'];
            if(isset($existing['setupOptions']))$value['setupOptions']=$existing['setupOptions'];
            if(isset($existing['menuLabels']))$value['menuLabels']=$existing['menuLabels'];
            foreach(['deliveryCities','activeDeliveryCities','shippingScope'] as $field)if(isset($existing[$field]))$value[$field]=$existing[$field];
            if(array_key_exists('shippingScope',$data))$value=CustomerShippingScope::apply($value,(array)$data['shippingScope']);
            if(isset($existing['workspacePolicy']))$value['workspacePolicy']=$existing['workspacePolicy'];
            if(isset($existing[InventoryBarcodeIdentity::POLICY]))$value[InventoryBarcodeIdentity::POLICY]=$existing[InventoryBarcodeIdentity::POLICY];
            if(isset($existing[InventoryLiveSkuIdentity::POLICY]))$value[InventoryLiveSkuIdentity::POLICY]=$existing[InventoryLiveSkuIdentity::POLICY];
            $payload=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if ($existing['version']) $this->db->run('UPDATE company_profiles SET payload=?,version=version+1 WHERE company_id=?',[$payload,$company]);
            else $this->db->run('INSERT INTO company_profiles(company_id,payload) VALUES(?,?)',[$company,$payload]);
            $this->db->run('UPDATE companies SET name=? WHERE id=?',[$name,$company]);
            $this->audit($actor,$company,'Müşteri bilgileri güncellendi',$company);
        });
    }
    public function users(int $company): array
    {
        LoginIdentity::install($this->db);AccountDirectorySchema::ensure($this->db);
        $users=$this->db->all("SELECT u.id,u.name,u.email,u.role,u.company_id AS companyId,n.username,COALESCE(c.active,1) AS active,(SELECT COUNT(*) FROM sessions s WHERE s.user_id=u.id AND s.expires>?) AS sessions FROM users u LEFT JOIN user_login_names n ON n.user_id=u.id LEFT JOIN account_controls c ON c.user_id=u.id WHERE u.company_id=? AND u.role='client' AND NOT EXISTS(SELECT 1 FROM account_profiles p WHERE p.user_id=u.id AND p.deleted_at IS NOT NULL) ORDER BY u.id",[time(),$company]);
        $profile=$this->profile($company,true);
        $roles=CustomerAccountRoles::roles($profile);
        $avatarVersion=$profile['hasLogo']?$profile['version']:null;
        return array_map(static fn(array $user)=>[
            ...$user,'storeRole'=>$roles[$user['id']]??null,'avatarVersion'=>$avatarVersion,
        ],$users);
    }
    public function customer(int $company,int $id): array
    {
        AccountDirectorySchema::ensure($this->db);
        $user=$this->db->one("SELECT u.*,COALESCE(c.active,1) AS active FROM users u LEFT JOIN account_controls c ON c.user_id=u.id WHERE u.id=? AND u.company_id=? AND u.role='client' AND NOT EXISTS(SELECT 1 FROM account_profiles p WHERE p.user_id=u.id AND p.deleted_at IS NOT NULL)",[$id,$company]);
        if (!$user) Http::fail(404,'Müşteri hesabı bulunamadı.');
        return $user;
    }
    public function revoke(int $id): void
    {
        $this->db->run('DELETE FROM sessions WHERE user_id=?',[$id]);
        $this->db->run('DELETE FROM password_resets WHERE user_id=?',[$id]);
        $this->db->run("UPDATE mail_outbox SET state='cancelled',payload=NULL WHERE user_id=? AND state IN ('pending','running') AND id NOT LIKE 'privacy-delete-%'",[$id]);
    }
    public function updateUser(array $actor,int $company,int $id,array $data): void
    {
        Security::admin($actor);LoginIdentity::install($this->db);
        $username=array_key_exists('username',$data)?LoginIdentity::validate($data['username']):null;
        $targetCompany=isset($data['companyId'])?filter_var($data['companyId'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):$company;if(!$targetCompany)Http::fail(400,'Müşteri seçin.');
        $name=Validation::text($data['name']??null,'Ad soyad',100);
        $email=mb_strtolower(Validation::text($data['email']??null,'E-posta',200));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !is_bool($data['active']??null)) Http::fail(400,'Hesap bilgilerini kontrol edin.');
        TransactionRetry::run($this->db,function() use($actor,$company,$id,$data,$name,$email,$username,$targetCompany) {
            $companyIds=array_unique([$company,$targetCompany]);sort($companyIds);foreach($companyIds as $companyId)if(!$this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$companyId]))Http::fail(404,'Müşteri bulunamadı.');
            $this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$id]);
            $old=$this->customer($company,$id);
            $oldUsername=LoginIdentity::read($this->db,$id);if(array_key_exists('previousUsername',$data)&&$data['previousUsername']!==$oldUsername)Http::fail(409,'Kullanıcı bilgileri değişti. Pencereyi yeniden açın.');
            if($username!==null)LoginIdentity::save($this->db,$id,$username);
            CustomerAccountAssignment::move($this->db,$actor,$id,$company,$targetCompany);
            if ($this->db->one('SELECT id FROM users WHERE email=? AND id<>?',[$email,$id])) Http::fail(409,'E-posta başka bir hesapta kullanılıyor.');
            $this->db->run('UPDATE users SET name=?,email=? WHERE id=?',[$name,$email,$id]);
            $this->db->run('DELETE FROM account_controls WHERE user_id=?',[$id]);
            $this->db->run('INSERT INTO account_controls(user_id,active) VALUES(?,?)',[$id,$data['active']?1:0]);
            if (!$data['active'] || $old['email']!==$email) $this->revoke($id);
            $this->audit($actor,$company,'Müşteri hesabı güncellendi',$id);
        });
    }
    public function setLogo(array $actor,int $company,?array $file): void
    {
        $encoded=null;
        if ($file!==null) {
            if (($file['error']??1)!==UPLOAD_ERR_OK || $file['size']>1048576 || !is_uploaded_file($file['tmp_name'])) Http::fail(400,'En fazla 1 MB boyutunda PNG, JPEG veya WebP yükleyin.');
            $raw=file_get_contents($file['tmp_name']); $info=@getimagesizefromstring($raw);
            if (!$info || !in_array($info['mime'],['image/png','image/jpeg','image/webp'],true) || $info[0]>2048 || $info[1]>2048) Http::fail(400,'Logo en fazla 2048 × 2048 piksel olmalı.');
            if (!function_exists('imagecreatefromstring')) Http::fail(503,'Sunucuda görsel işleme etkinleştirilmeli.');
            $source=@imagecreatefromstring($raw);
            if (!$source) Http::fail(400,'Görsel okunamadı.');
            $ratio=min(1,512/$info[0],512/$info[1]);
            $image=imagecreatetruecolor(max(1,(int)($info[0]*$ratio)),max(1,(int)($info[1]*$ratio)));
            imagealphablending($image,false); imagesavealpha($image,true);
            imagecopyresampled($image,$source,0,0,0,0,imagesx($image),imagesy($image),$info[0],$info[1]);
            ob_start(); imagepng($image,null,8); $encoded=base64_encode(ob_get_clean());
            imagedestroy($source); imagedestroy($image);
        }
        $this->db->transaction(function() use($actor,$company,$encoded) {
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            if ($this->db->one('SELECT company_id FROM company_profiles WHERE company_id=?',[$company])) $this->db->run('UPDATE company_profiles SET logo=?,version=version+1 WHERE company_id=?',[$encoded,$company]);
            else $this->db->run("INSERT INTO company_profiles(company_id,payload,logo) VALUES(?,'{}',?)",[$company,$encoded]);
            $this->audit($actor,$company,$encoded?'Müşteri logosu güncellendi':'Müşteri logosu kaldırıldı',$company);
        });
    }
    public function logo(int $company): never
    {
        $row=$this->db->one('SELECT logo FROM company_profiles WHERE company_id=?',[$company]);
        if (empty($row['logo'])) Http::fail(404,'Logo bulunamadı.');
        $original=base64_decode($row['logo'],true);
        header('Content-Type: image/png');
        echo match($_GET['fit']??''){ 'payment'=>CompanyLogoPresentation::paymentFrame($original), '1'=>CompanyLogoPresentation::fit($original), default=>$original }; exit;
    }
}

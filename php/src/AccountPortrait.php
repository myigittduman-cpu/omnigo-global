<?php
declare(strict_types=1);
namespace Omnigo;

final class AccountPortrait
{
    public function __construct(private Database $db){}
    public static function normalize(string $bytes):string
    {
        if(strlen($bytes)>2097152)Http::fail(400,'Profil resmi en fazla 2 MB olmalı.');
        $info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info['mime'],['image/png','image/jpeg','image/webp'],true)||$info[0]>2048||$info[1]>2048)Http::fail(400,'En fazla 2048 × 2048 piksel PNG, JPEG veya WebP seçin.');
        if(!function_exists('imagecreatefromstring'))Http::fail(503,'Görsel işleme şu anda kullanılamıyor.');
        $source=@imagecreatefromstring($bytes);if(!$source)Http::fail(400,'Görsel okunamadı.');
        $ratio=min(1,256/$info[0],256/$info[1]);$image=imagecreatetruecolor(max(1,(int)round($info[0]*$ratio)),max(1,(int)round($info[1]*$ratio)));
        imagealphablending($image,false);imagesavealpha($image,true);imagecopyresampled($image,$source,0,0,0,0,imagesx($image),imagesy($image),$info[0],$info[1]);
        ob_start();imagepng($image,null,8);$png=ob_get_clean();imagedestroy($source);imagedestroy($image);return base64_encode($png);
    }
    public function save(array $actor,int $id,mixed $revision,?string $bytes):array
    {
        Security::admin($actor);$image=$bytes===null?null:self::normalize($bytes);$directory=new AccountDirectory($this->db);
        return TransactionRetry::run($this->db,function()use($actor,$id,$revision,$image,$directory){
            $this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$id]);$row=$directory->row($id);$directory->assertRevision($row,$revision);
            if($row['role']==='client')Http::fail(409,'Müşteri profil resmi bağlı mağazanın logosundan alınır. Mağaza logosunu güncelleyin.');
            if($row['profile_version']!==null)$this->db->run('UPDATE account_profiles SET avatar=?,version=version+1 WHERE user_id=?',[$image,$id]);
            else $this->db->run('INSERT INTO account_profiles(user_id,avatar) VALUES(?,?)',[$id,$image]);
            $directory->audit($actor,$id,$image?'Profil resmi güncellendi':'Profil resmi kaldırıldı');return AccountDirectory::publicRow($directory->row($id));
        });
    }
    public function show(array $actor,int $id):never
    {
        if($actor['role']!=='admin'&&(int)$actor['id']!==$id)Http::fail(403,'Bu profil resmine erişemezsiniz.');
        $row=(new AccountDirectory($this->db))->row($id);$image=(new AccountPortraitSource($this->db))->image($row);
        if(empty($image))Http::fail(404,'Profil resmi bulunamadı.');
        header('Cache-Control: private, no-store');
        header('Content-Type: image/png');echo base64_decode($image,true);exit;
    }
}

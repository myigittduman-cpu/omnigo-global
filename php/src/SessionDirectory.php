<?php
declare(strict_types=1);
namespace Omnigo;

/** Session handles reveal neither cookies nor the stored session token. */
final class SessionDirectory
{
    private string $key;
    public function __construct(private Database $db,string $keyFile){$key=@file_get_contents($keyFile);if(!is_string($key)||strlen($key)!==32)throw new \RuntimeException('Oturum güvenliği hazır değil.');$this->key=$key;}
    private function handle(array $row):string{return (int)$row['user_id'].'.'.hash_hmac('sha256','revoke:'.$row['token'],$this->key);}
    public function listing(array $actor,array $filter=[]):array
    {
        $page=max(1,min(10000,(int)($filter['page']??1)));$where="COALESCE(ac.active,1)=1 AND ap.deleted_at IS NULL AND (u.role='admin' OR u.company_id IS NOT NULL) AND (u.id<>? OR s.token IS NOT NULL)";$params=[(int)$actor['id']];
        $target=isset($filter['user'])&&$filter['user']!==''?filter_var($filter['user'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
        if($target===false)Http::fail(400,'Kullanıcı seçimini kontrol edin.');
        if($actor['role']!=='admin'){if($target!==null&&$target!==(int)$actor['id'])Http::fail(403,'Yalnız kendi oturumlarınızı görüntüleyebilirsiniz.');$target=(int)$actor['id'];}
        if($target!==null){$where.=' AND u.id=?';$params[]=$target;}
        $q=trim((string)($filter['q']??''));if(mb_strlen($q)>150)Http::fail(400,'Arama metni çok uzun.');
        if($q!==''){$where.=' AND (LOWER(u.name) LIKE ? OR LOWER(c.name) LIKE ?)';$term='%'.mb_strtolower($q).'%';array_push($params,$term,$term);}
        $rows=$this->db->all('SELECT s.token,u.id AS user_id,u.name,u.role,c.name AS company,logins.last_login FROM users u LEFT JOIN sessions s ON s.user_id=u.id AND s.token=? AND s.expires>? LEFT JOIN companies c ON c.id=u.company_id LEFT JOIN account_controls ac ON ac.user_id=u.id LEFT JOIN account_profiles ap ON ap.user_id=u.id LEFT JOIN (SELECT target_id,MAX(created) AS last_login FROM account_audit WHERE action=? GROUP BY target_id) logins ON logins.target_id=u.id WHERE '.$where.' ORDER BY CASE WHEN s.token IS NOT NULL THEN 0 ELSE 1 END,logins.last_login DESC,u.id LIMIT 26 OFFSET '.(($page-1)*25),[(string)($actor['token']??''),time(),AccountLoginActivity::ACTION,...$params]);
        $items=array_map(fn($row)=>['id'=>$row['token']?$this->handle($row):null,'userId'=>(int)$row['user_id'],'name'=>$row['name'],'role'=>$row['role'],'company'=>$row['company'],'lastLogin'=>$row['last_login']?gmdate('c',(int)$row['last_login']):null,'current'=>$row['token']!==null],array_slice($rows,0,25));
        return ['items'=>$items,'page'=>$page,'hasMore'=>count($rows)>25];
    }
    public function revoke(array $actor,array $data):void
    {
        if(!is_string($data['id']??null)||!preg_match('/^([1-9][0-9]*)\.[a-f0-9]{64}$/D',$data['id'],$match))Http::fail(400,'Oturum seçimini kontrol edin.');
        $user=(int)$match[1];if($actor['role']!=='admin'&&$user!==(int)$actor['id'])Http::fail(403,'Başka bir kullanıcının oturumunu kapatamazsınız.');
        TransactionRetry::run($this->db,function()use($actor,$data,$user){
            $this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$user]);$found=null;
            foreach($this->db->all('SELECT token,user_id FROM sessions WHERE user_id=? AND expires>?'.$this->db->lock(),[$user,time()]) as $row)if(hash_equals($this->handle($row),$data['id'])){$found=$row;break;}
            if(!$found)Http::fail(404,'Oturum zaten kapanmış veya bulunamadı.');$token=$found['token'];
            if(hash_equals((string)($actor['token']??''),$token))Http::fail(400,'Bu cihazdaki oturumu kapatmak için Çıkış yap düğmesini kullanın.');
            $this->db->run("UPDATE push_deliveries SET state='cancelled' WHERE device_id IN (SELECT id FROM notification_devices WHERE user_id=? AND session_token=?) AND state IN ('pending','sending')",[$user,$token]);
            $this->db->run('UPDATE notification_devices SET enabled=0,generation=generation+1 WHERE user_id=? AND session_token=?',[$user,$token]);
            $this->db->run('DELETE FROM client_session_operators WHERE session_token=?',[$token]);$this->db->run('DELETE FROM admin_mfa_sessions WHERE token=? AND user_id=?',[$token,$user]);$this->db->run('DELETE FROM sessions WHERE token=? AND user_id=?',[$token,$user]);
            (new AccountDirectory($this->db))->audit($actor,$user,'Seçilen oturum kapatıldı');
        });
    }
}

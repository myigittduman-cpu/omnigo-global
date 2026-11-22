<?php
declare(strict_types=1);
namespace Omnigo;

/** Threads with customers have one immutable tenant. Administration-only groups have none. */
final class ChatAccess
{
    public const ACTIVE="COALESCE(a.active,1)=1 AND p.deleted_at IS NULL";
    public const ACCOUNT_STATE="LEFT JOIN account_controls a ON a.user_id=u.id LEFT JOIN account_profiles p ON p.user_id=u.id";
    public const ACCOUNTS='users u '.self::ACCOUNT_STATE;

    public function __construct(private Database $db){}

    public function thread(array $user,int $id,bool $lock=false):array
    {
        $row=$this->db->one('SELECT t.* FROM chat_threads t JOIN chat_members m ON m.thread_id=t.id WHERE t.id=? AND m.user_id=?'
            .($lock?$this->db->lock():''),[$id,$user['id']]);
        if(!$row||!self::permits($user,$row))Http::fail(404,'Sohbet bulunamadı.');
        return $row;
    }

    public static function permits(array $user,array $thread):bool
    {
        return ($user['role']??'')==='admin'||(($user['role']??'')==='client'
            && (int)($user['company_id']??0)>0 && (int)$user['company_id']===(int)$thread['company_id']);
    }

    public function contacts(array $user,?int $company):array
    {
        if($user['role']==='client')$company=(int)$user['company_id'];
        $rows=$this->db->all('SELECT u.id,u.name,u.role,COALESCE(s.last_seen,0) last_seen FROM '.self::ACCOUNTS.
            " LEFT JOIN chat_presence s ON s.user_id=u.id WHERE ".self::ACTIVE.
            " AND u.id<>? AND (u.role='admin' OR (u.role='client' AND u.company_id=?)) ORDER BY u.role,u.name LIMIT 250",
            [$user['id'],$company??0]);
        return array_map(self::publicAccount(...),$rows);
    }

    public static function publicAccount(array $row):array
    {
        return ['id'=>(int)$row['id'],'name'=>$row['name'],'admin'=>$row['role']==='admin','online'=>(int)$row['last_seen']>=time()-45];
    }

    public function participants(array $user,array $ids,bool $group=false):?int
    {
        $in=implode(',',array_fill(0,count($ids),'?'));
        $rows=$this->db->all("SELECT u.id,u.role,u.company_id FROM ".self::ACCOUNTS." WHERE ".self::ACTIVE." AND u.id IN ($in)",$ids);
        $companies=[];
        foreach($rows as $row){
            if($group&&$user['role']==='client'&&$row['role']==='admin')Http::fail(403,'Grup sohbetine yalnız kendi mağazanızdaki kullanıcıları ekleyebilirsiniz.');
            if($row['role']==='client'&&(int)$row['company_id']>0)$companies[(int)$row['company_id']]=true;
            elseif($row['role']!=='admin')Http::fail(403,'Bu kullanıcıyla sohbet açılamıyor.');
        }
        $company=array_key_first($companies);
        if(count($rows)!==count($ids)||count($companies)>1||($user['role']==='client'&&$company!==(int)$user['company_id']))
            Http::fail(403,'Bir müşteri sohbetinde yalnız aynı mağazanın kullanıcıları ve Omnigo yer alabilir.');
        return $company;
    }
}

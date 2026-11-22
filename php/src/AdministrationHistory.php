<?php
declare(strict_types=1);
namespace Omnigo;

/** Account operations and permission changes share a bounded, admin-only history. */
final class AdministrationHistory
{
    public function __construct(private Database $db){}
    public function listing(array $actor,array $filter=[]):array
    {
        Security::admin($actor);$page=max(1,min(10000,(int)($filter['page']??1)));$q=trim((string)($filter['q']??''));if(mb_strlen($q)>150)Http::fail(400,'Arama metni çok uzun.');
        $timestamp=$this->db->mysql()?"UNIX_TIMESTAMP(REPLACE(SUBSTRING(a.created,1,19),'T',' '))":"CAST(strftime('%s',a.created) AS INTEGER)";
        $actions=['Müşteri bölümleri ve kullanıcı yetkileri güncellendi','Müşteri kullanıcısı oluşturuldu ve yetkileri tanımlandı','Müşteri hesabı güncellendi','Müşteri kullanıcısının mağazası değiştirildi','Müşteri için geçici şifre belirlendi','Müşteri oturumları kapatıldı','Müşteri giriş hesabı kaldırıldı; geçmiş işlem kimliği korundu'];
        $sql="SELECT * FROM (SELECT aa.id,'account' AS kind,aa.created AS occurred,actor.name AS actor_name,target.name AS target_name,aa.action,NULL AS company FROM account_audit aa LEFT JOIN users actor ON actor.id=aa.actor_id LEFT JOIN users target ON target.id=aa.target_id UNION ALL SELECT a.id,'permission' AS kind,$timestamp AS occurred,actor.name AS actor_name,c.name AS target_name,a.action,c.name AS company FROM audit a LEFT JOIN users actor ON actor.id=a.user_id LEFT JOIN companies c ON c.id=a.company_id WHERE a.action IN (".implode(',',array_fill(0,count($actions),'?')).')) history';$params=$actions;
        if($q!==''){$sql.=' WHERE (LOWER(actor_name) LIKE ? OR LOWER(target_name) LIKE ? OR LOWER(action) LIKE ?)';$term='%'.mb_strtolower($q).'%';array_push($params,$term,$term,$term);}
        $sql.=($q!==''?' AND':' WHERE').' action<>?';$params[]=AccountLoginActivity::ACTION;
        $rows=$this->db->all($sql.' ORDER BY occurred DESC,id DESC LIMIT 26 OFFSET '.(($page-1)*25),$params);
        return ['items'=>array_map(fn($row)=>['id'=>$row['kind'].':'.$row['id'],'createdAt'=>gmdate('c',(int)$row['occurred']),'actor'=>$row['actor_name']??'Omnigo','target'=>$row['target_name']??'Kaldırılan hesap','action'=>$row['action'],'company'=>$row['company']],array_slice($rows,0,25)),'page'=>$page,'hasMore'=>count($rows)>25];
    }
}

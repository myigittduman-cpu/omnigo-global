<?php
declare(strict_types=1);
namespace Omnigo;

/** A database-backed installation-wide switch shared by every web and worker process. */
final class OperationMailPreference
{
    private const KEY='operation_mail_enabled';
    public function __construct(private Database $db){}
    public function read():array
    {
        $row=$this->db->one('SELECT preference_value,version FROM application_preferences WHERE preference_key=?',[self::KEY]);
        if(!$row)throw new \RuntimeException('Operasyon e-posta tercihi kurulmamış.');
        return ['enabled'=>$row['preference_value']==='1','version'=>(int)$row['version']];
    }
    public function enabled():bool{return $this->read()['enabled'];}
    public function save(mixed $enabled,mixed $version):array
    {
        if(!is_bool($enabled))Http::fail(400,'E-posta tercihini kontrol edin.');
        if(!is_int($version)||$version<1)Http::fail(400,'E-posta tercihi sürümünü kontrol edin.');
        return $this->db->transaction(function()use($enabled,$version){
            $row=$this->db->one('SELECT preference_value,version FROM application_preferences WHERE preference_key=?'.$this->db->lock(),[self::KEY]);
            if(!$row)Http::fail(503,'E-posta tercihi şu anda okunamıyor.');
            if((int)$row['version']!==$version)Http::fail(409,'Bildirim tercihi başka bir cihazda değişti. Ayarları yeniden açın.');
            if(($row['preference_value']==='1')===$enabled)return ['enabled'=>$enabled,'version'=>$version];
            $next=$version+1;
            $updated=$this->db->run('UPDATE application_preferences SET preference_value=?,version=?,updated=? WHERE preference_key=? AND version=?',[$enabled?'1':'0',$next,time(),self::KEY,$version]);
            if($updated->rowCount()!==1)Http::fail(409,'Bildirim tercihi başka bir cihazda değişti. Ayarları yeniden açın.');
            return ['enabled'=>$enabled,'version'=>$next];
        });
    }
}

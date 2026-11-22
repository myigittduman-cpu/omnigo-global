<?php
declare(strict_types=1);
namespace Omnigo;

/** Administrative evidence never overwrites imported order, stock or financial records. */
final class OperationDossier
{
    public const STATES=['pending','delivered','return_received','damage_review','closed'];
    public function __construct(private Database $db,private DeliveryCipher $cipher,private OperationFiles $files){}
    public function load(array $user,int $company,array $target):array
    {
        Security::admin($user);$row=$this->db->one('SELECT payload,version,updated FROM operation_dossiers WHERE company_id=? AND dossier_key=?',[$company,$target['key']]);
        $value=$row?$this->cipher->open($company,'operation-dossier:'.$target['key'],$row['payload']):['fields'=>['state'=>'pending','receiver'=>'','eventDate'=>'','eventTime'=>'','serialNumbers'=>'','notes'=>''],'files'=>[],'history'=>[]];
        return ['target'=>$target,'version'=>(int)($row['version']??0),'updated'=>$row['updated']??null,...array_intersect_key($value,array_flip(['fields','files','history']))];
    }
    private static function fields(array $data):array
    {
        $result=[];foreach(['receiver'=>150,'eventDate'=>10,'eventTime'=>5,'serialNumbers'=>1200,'notes'=>3000] as $key=>$limit){$value=$data[$key]??'';if(!is_string($value)||mb_strlen($value)>$limit)Http::fail(400,'Dosya alanlarını ve metin uzunluklarını kontrol edin.');$result[$key]=trim($value);}
        $result['state']=$data['state']??'pending';if(!in_array($result['state'],self::STATES,true))Http::fail(400,'Dosya durumu geçersiz.');
        if($result['eventDate']!=='')Validation::date($result['eventDate']);
        if($result['eventTime']!==''&&(!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D',$result['eventTime'])||$result['eventDate']===''))Http::fail(400,'Saat için önce tarihi girin; saat biçimi SS:DD olmalı.');
        return $result;
    }
    public function save(array $user,int $company,array $target,array $data,?array $upload=null):array
    {
        Security::admin($user);$fields=self::fields((array)($data['fields']??[]));$version=$data['version']??null;$request=$data['requestId']??'';
        if(!is_int($version)||$version<0||!is_string($request)||!preg_match('/^[a-f0-9]{48}$/D',$request))Http::fail(400,'Kayıt sürümünü kontrol edin.');
        $file=$upload?OperationFiles::prepare($upload['bytes']):null;$hash=hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR).($upload['bytes']??''));$newKey=null;
        try{
            if($file){$newKey=bin2hex(random_bytes(24));$this->files->write($company,$target['key'],$newKey,$file);}
            $result=TransactionRetry::run($this->db,function()use($user,$company,$target,$version,$request,$fields,$file,$upload,$hash,&$newKey){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $row=$this->db->one('SELECT * FROM operation_dossiers WHERE company_id=? AND dossier_key=?'.$this->db->lock(),[$company,$target['key']]);
            $before=$row?$this->cipher->open($company,'operation-dossier:'.$target['key'],$row['payload']):['files'=>[],'history'=>[]];
            if(($before['lastRequest']??'')===$request){if(($before['lastHash']??'')!==$hash)Http::fail(409,'Aynı kayıt isteği farklı bilgiler içeriyor.');return ['duplicate'=>true,...$this->load($user,$company,$target)];}
            if((int)($row['version']??0)!==$version)Http::fail(409,'Dosya başka bir yetkili tarafından güncellendi. Girdiğiniz bilgiler korunuyor; dosyayı yeniden açıp kontrol edin.');
            $now=gmdate('c');$files=$before['files'];
            if($file){if(count($files)>=12)Http::fail(400,'Bir sipariş dosyasına en fazla 12 belge eklenebilir.');$name=mb_substr(preg_replace('/[\x00-\x1F\/\\\\]/u','_',basename($upload['name']??'belge')),0,150);$files[]=['key'=>$newKey,'name'=>$name?:'Belge','mime'=>$file['mime'],'bytes'=>strlen($file['bytes']),'created'=>$now,'actor'=>$user['name']??'Omnigo'];}
            $value=['fields'=>$fields,'files'=>$files,'history'=>array_slice([...($before['history']??[]),['at'=>$now,'actor'=>$user['name']??'Omnigo','action'=>$file?'Bilgiler kaydedildi ve belge eklendi':'Dosya bilgileri kaydedildi','state'=>$fields['state']]],-30),'lastRequest'=>$request,'lastHash'=>$hash];
            $payload=$this->cipher->seal($company,'operation-dossier:'.$target['key'],$value);
            if($row)$this->db->run('UPDATE operation_dossiers SET payload=?,version=version+1,updated=? WHERE company_id=? AND dossier_key=?',[$payload,$now,$company,$target['key']]);
            else $this->db->run('INSERT INTO operation_dossiers(company_id,dossier_key,payload,version,updated) VALUES(?,?,?,1,?)',[$company,$target['key'],$payload,$now]);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$user['id'],$company,'Teslimat/iade dosyası kaydedildi',null,$row['payload']??null,$payload,$now]);
            return $this->load($user,$company,$target);
        });if(!empty($result['duplicate'])&&$newKey)$this->files->discard($newKey);return $result;}catch(\Throwable $error){if($newKey)$this->files->discard($newKey);throw $error;}
    }
    public function file(array $user,int $company,array $target,string $key):array
    {
        $data=$this->load($user,$company,$target);$items=array_values(array_filter($data['files'],fn($file)=>hash_equals($file['key'],$key)));
        if(count($items)!==1)Http::fail(404,'Belge bu sipariş dosyasına ait değil.');return $this->files->read($company,$target['key'],$items[0]);
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

/** Short-lived edit leases in the shared private storage of both portal hosts. */
final class ShipmentEditingPresence
{
    public const TTL=90;
    private mixed $writeGuard=null;
    public function __construct(private ?string $folder=null,private ?\Closure $clock=null){$this->folder??=dirname(__DIR__).'/storage/shipment-editing';}
    private function now():int{return $this->clock?($this->clock)():time();}
    private function open(int $company,int $mode):mixed
    {
        if(!is_dir($this->folder)&&!@mkdir($this->folder,0700,true)&&!is_dir($this->folder))Http::fail(503,'Düzenleme durumu kontrol edilemiyor. Lütfen tekrar deneyin.');
        $handle=@fopen($this->folder.'/'.$company.'.json','c+');
        if(!$handle)Http::fail(503,'Düzenleme durumu kontrol edilemiyor.');
        $deadline=microtime(true)+2;
        do{if(flock($handle,$mode|LOCK_NB))return $handle;usleep(20000);}while(microtime(true)<$deadline);
        fclose($handle);Http::fail(503,'Devam eden işlem tamamlanıyor. Lütfen tekrar deneyin.');
    }
    private function leases(mixed $handle):array
    {
        rewind($handle);$raw=stream_get_contents($handle,65537);
        if($raw==='')return [];
        $leases=json_decode($raw,true);
        if(strlen($raw)>65536||!is_array($leases))Http::fail(503,'Düzenleme durumu okunamadı.');
        return array_filter($leases,fn($lease)=>is_array($lease)&&(int)($lease['expires']??0)>$this->now());
    }
    private function summary(array $leases):array{return ['editing'=>count($leases)>0,'expiresAt'=>$leases?max(array_column($leases,'expires')):null];}
    public function status(array $user,int $company):array
    {
        ShipmentAccess::company($user,$company);$handle=$this->open($company,LOCK_SH);
        try{return $this->summary($this->leases($handle));}finally{fclose($handle);}
    }
    public function update(array $user,int $company,array $input):array
    {
        Security::admin($user);ShipmentAccess::company($user,$company);
        $token=$input['token']??null;$action=$input['action']??null;
        if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token)||!in_array($action,['renew','release'],true))Http::fail(400,'Düzenleme oturumu geçersiz.');
        // Different tabs and authenticated sessions cannot release one another's lease.
        $key=hash('sha256',$user['id'].':'.($user['token']??'').':'.$token);
        $handle=$this->open($company,LOCK_EX);
        try{
            $leases=$this->leases($handle);
            if($action==='release')unset($leases[$key]);
            else{if(!isset($leases[$key])&&count($leases)>=20)Http::fail(429,'Çok fazla açık düzenleme var. Diğer sekmeleri kapatın.');$leases[$key]=['expires'=>$this->now()+self::TTL];}
            $json=json_encode($leases,JSON_THROW_ON_ERROR);rewind($handle);
            if(fwrite($handle,$json)!==strlen($json)||!ftruncate($handle,strlen($json))||!fflush($handle))Http::fail(503,'Düzenleme durumu kaydedilemedi.');
            return $this->summary($leases);
        }finally{fclose($handle);}
    }
    /** Held until the entire HTTP write finishes, serializing against lease acquisition. */
    public function holdClientWrite(array $user,int $company):void
    {
        ShipmentAccess::company($user,$company);if($user['role']==='admin')return;
        $handle=$this->open($company,LOCK_SH);
        try{if($this->leases($handle))Http::fail(423,'Şu anda yönetici bilgileri güncelliyor. Lütfen bekleyin.');$this->writeGuard=$handle;}
        catch(\Throwable $error){fclose($handle);throw $error;}
    }
    public function __destruct(){if(is_resource($this->writeGuard))fclose($this->writeGuard);}
}

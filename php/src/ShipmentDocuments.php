<?php
declare(strict_types=1);
namespace Omnigo;

/** Immutable encrypted documents live outside the public directory; rows only contain references. */
final class ShipmentDocuments
{
    public function __construct(private Database $db,private DeliveryCipher $cipher,private string $folder){}
    private function row(array $user,int $company,int $id,string $kind,array $selection,bool $lock=false,bool $read=false): array
    {
        ShipmentAccess::company($user,$company);if(!in_array($kind,['invoice','delivery'],true))Http::fail(400,'Belge türü geçersiz.');
        [$scope,$params]=CustomerCities::scope($this->db,$company,ShipmentRepository::scope($company,$selection));
        $row=$this->db->one('SELECT * FROM shipment_entries WHERE id=? AND '.$scope.($lock?$this->db->lock():''),[$id,...$params]);
        if(!$row||($selection[0]!=='all'&&$row['city']!==$selection[0]))Http::fail(404,'Sevkiyat kaydı bulunamadı.');
        if($user['role']==='client'){
            $payload=$this->cipher->open($company,'shipment:'.$id,$row['payload']);
            if(!$read)ShipmentCustomerVisibility::requireVisible($user,$row,$payload);
            else{
                $flags=$kind==='delivery'?(new CustomerPermissions($this->db,$company))->archivedDeliveryFlags($user)
                    :CustomerSensitiveVisibility::flags($this->db,$user,$company,CustomerPermissionCatalog::DOCUMENT_PAGES[$kind]);
                if(!ShipmentCustomerVisibility::readable($row,$payload,!$flags['cancellations'],!$flags['returns'])){
                    Http::fail(404,'Sevkiyat kaydı bulunamadı.');
                }
            }
        }
        return $row;
    }
    private function path(string $key): string
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$key))Http::fail(404,'Belge bulunamadı.');return $this->folder.'/'.$key.'.bin';
    }
    public function save(array $user,int $company,int $id,string $kind,array $selection,int $version,string $request,string $bytes): array
    {
        if($kind==='delivery')Http::fail(410,'Bu belge işlemi kullanımdan kaldırıldı.');
        $this->row($user,$company,$id,$kind,$selection);
        if(!preg_match('/^[a-f0-9]{48}$/D',$request)||$version<1)Http::fail(400,'Yükleme bilgileri geçersiz.');
        $mime='image/jpeg';$info=[0,0];
        if(str_starts_with($bytes,'%PDF-')){ShipmentPdf::validate($bytes);$safe=$bytes;$mime='application/pdf';}
        else{
        if(strlen($bytes)>2097152||strlen($bytes)<8)Http::fail(413,'İşlenen görsel en fazla 2 MB, PDF en fazla 4 MB olabilir.');
        $info=@getimagesizefromstring($bytes);
        if(!$info||$info['mime']!=='image/jpeg'||$info[0]>4000||$info[1]>4000||$info[0]*$info[1]>16000000)Http::fail(415,'Geçerli bir JPEG görseli seçin.');
        if(!function_exists('imagecreatefromstring'))Http::fail(503,'Sunucu görsel işleme özelliği hazır değil.');
        $image=@imagecreatefromstring($bytes);if(!$image)Http::fail(415,'Görsel okunamadı.');
        ob_start();imagejpeg($image,null,92);$safe=ob_get_clean();imagedestroy($image);
        }
        $hash=hash('sha256',$bytes);$file=null;
        try{return $this->db->transaction(function()use($user,$company,$id,$kind,$selection,$version,$request,$safe,$hash,$info,$mime,&$file){
            $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
            $row=$this->row($user,$company,$id,$kind,$selection,true);$payload=$this->cipher->open($company,'shipment:'.$id,$row['payload']);$old=$payload['documents'][$kind]??null;
            if(($old['request']??null)===$request){if(($old['hash']??null)!==$hash)Http::fail(409,'Yükleme isteği farklı bir dosyaya ait.');return ['version'=>(int)$row['version'],'saved'=>true];}
            if((int)$row['version']!==$version)Http::fail(409,'Kayıt değişti. Pencereyi kapatıp listeyi yenileyerek yeniden yükleyin.');
            if($old&&$user['role']!=='admin')(new CustomerPermissions($this->db,$company))->require($user,CustomerPermissionCatalog::DOCUMENT_PAGES[$kind],'replaceDocuments');
            if(!is_dir($this->folder)&&!mkdir($this->folder,0700,true)&&!is_dir($this->folder))Http::fail(503,'Belge klasörü hazırlanamadı.');
            $key=bin2hex(random_bytes(24));$file=$this->path($key);$encrypted=$this->cipher->seal($company,'shipment-document:'.$id.':'.$kind.':'.$key,['bytes'=>base64_encode($safe)]);
            $handle=fopen($file,'xb');if(!$handle)Http::fail(503,'Belge saklanamadı.');
            try{if(fwrite($handle,$encrypted)!==strlen($encrypted))Http::fail(503,'Belge saklanamadı.');}finally{fclose($handle);}chmod($file,0600);
            $payload['documents'][$kind]=['key'=>$key,'request'=>$request,'hash'=>$hash,'mime'=>$mime,'width'=>$info[0],'height'=>$info[1],'updated'=>gmdate('c')];
            $status=ShipmentColorPolicy::status($row,$payload);
            $this->db->run('UPDATE shipment_entries SET payload=?,row_status=?,invoiced=?,completed=?,cancelled=?,shipped=?,version=version+1,updated=? WHERE id=?',[$this->cipher->seal($company,'shipment:'.$id,$payload),$status,(int)!empty($payload['documents']['invoice']),(int)($status==='completed'),(int)($status==='cancelled'),(int)($status==='shipped'),gmdate('c'),$id]);
            $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$user['id'],$company,'shipment.document',$id,json_encode(['kind'=>$kind,'key'=>$old['key']??null]),json_encode(['kind'=>$kind,'key'=>$key]),gmdate('c')]);
            $field=$kind==='invoice'?'invoiceDocument':'deliveryDocument';
            (new ShipmentChangeNotifications($this->db,$this->cipher))->record($user,$company,$id,'document',[$field=>$old?'Önceki belge':null],[$field=>$old?'Belge yenilendi':'Belge yüklendi']);
            return ['version'=>$version+1,'saved'=>true];
        });}catch(\Throwable $error){if($file&&is_file($file))unlink($file);throw $error;}
    }
    public function removeDelivery(array $user,int $company,int $id,array $selection,mixed $version): array
    {
        Http::fail(410,'Bu belge işlemi kullanımdan kaldırıldı.');
    }

    public function load(array $user,int $company,int $id,string $kind,array $selection): array
    {
        $row=$this->row($user,$company,$id,$kind,$selection,read:true);$payload=$this->cipher->open($company,'shipment:'.$id,$row['payload']);$meta=$payload['documents'][$kind]??null;
        if(!$meta)Http::fail(404,'Bu belge henüz yüklenmemiş.');$file=$this->path($meta['key']);
        if(!is_file($file)||filesize($file)>8000000)Http::fail(404,'Belge dosyası bulunamadı.');
        $value=$this->cipher->open($company,'shipment-document:'.$id.':'.$kind.':'.$meta['key'],file_get_contents($file));
        return ['bytes'=>$value['bytes'],'mime'=>$meta['mime']??'image/jpeg','width'=>$meta['width'],'height'=>$meta['height']];
    }
}

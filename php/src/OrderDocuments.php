<?php
declare(strict_types=1);
namespace Omnigo;
/** Separate from marketplace rows: sync jobs cannot overwrite uploaded files or their versions. */
final class OrderDocuments
{
    public function __construct(private Database $db,private DeliveryCipher $cipher,private PrivateDocumentFile $files){}
    private function reference(array $user,int $company,int $id,string $action='documents'):string
    {
        (new CustomerPermissions($this->db,$company))->require($user,'orders',$action);
        $order=(new OrderDetails($this->db,$this->cipher))->detail($user,$company,$id);
        return hash('sha256',$order['provider'].':'.$order['externalId']);
    }
    private static function kind(string $kind):void{if(!in_array($kind,['invoice','delivery'],true))Http::fail(400,'Belge türü geçersiz.');}
    private function row(int $company,string $reference,string $kind):?array{return $this->db->one('SELECT * FROM order_documents WHERE company_id=? AND reference_key=? AND kind=?',[$company,$reference,$kind]);}
    private static function meta(?array $row):array{return $row?json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR):[];}
    public function listing(array $user,int $company,int $id):array
    {
        $reference=$this->reference($user,$company,$id);$items=[];
        foreach(['invoice','delivery'] as $kind){$row=$this->row($company,$reference,$kind);$meta=self::meta($row);$items[$kind]=['version'=>(int)($row['version']??0),'present'=>isset($meta['key']),'updated'=>$row['updated']??null,'mime'=>$meta['mime']??null];}
        return ['items'=>$items,'revision'=>ShipmentRevision::read($this->db,$company)];
    }
    public function load(array $user,int $company,int $id,string $kind):array
    {
        self::kind($kind);$reference=$this->reference($user,$company,$id);$meta=self::meta($this->row($company,$reference,$kind));
        if(!isset($meta['key']))Http::fail(404,'Bu belge henüz yüklenmemiş.');return $this->files->read($company,$reference,$kind,$meta);
    }
    public function change(array $user,int $company,int $id,string $kind,int $version,string $request,?string $bytes):array
    {
        self::kind($kind);$reference=$this->reference($user,$company,$id,$bytes===null?'deleteDocuments':'upload');
        if($version<0||!preg_match('/^[a-f0-9]{48}$/D',$request))Http::fail(400,'Belge sürümü ve işlem kimliğini kontrol edin.');
        $new=null;$old=null;
        try{
            $result=TransactionRetry::run($this->db,function()use($user,$company,$id,$kind,$version,$request,$bytes,$reference,&$new,&$old){
                $this->db->one('SELECT id FROM companies WHERE id=?'.$this->db->lock(),[$company]);
                $policy=new CustomerPermissions($this->db,$company);$policy->require($user,'orders',$bytes===null?'deleteDocuments':'upload');
                $row=$this->row($company,$reference,$kind);$old=self::meta($row);$hash=$bytes===null?'deleted':hash('sha256',$bytes);
                if(($old['request']??null)===$request){if(($old['hash']??null)!==$hash)Http::fail(409,'İşlem kimliği farklı bir belgeye ait.');return ['version'=>(int)$row['version'],'saved'=>true];}
                if((int)($row['version']??0)!==$version)Http::fail(409,'Belge başka bir oturumda değişti. Yenileyip tekrar deneyin.');
                if($bytes!==null&&isset($old['key']))$policy->require($user,'orders','replaceDocuments');
                if($new)$this->files->remove($new['key']);
                $new=$bytes===null?null:$this->files->write($company,$reference,$kind,$bytes);
                $payload=json_encode([...($new??[]),'hash'=>$hash,'request'=>$request],JSON_THROW_ON_ERROR);$now=gmdate('c');
                if($row)$this->db->run('UPDATE order_documents SET payload=?,version=version+1,updated=? WHERE company_id=? AND reference_key=? AND kind=?',[$payload,$now,$company,$reference,$kind]);
                else $this->db->run('INSERT INTO order_documents(company_id,reference_key,kind,payload,updated) VALUES(?,?,?,?,?)',[$company,$reference,$kind,$payload,$now]);
                $this->db->run('INSERT INTO audit(user_id,company_id,action,record_id,before_value,after_value,created) VALUES(?,?,?,?,?,?,?)',[$user['id'],$company,$bytes===null?'Sipariş belgesi silindi':(isset($old['key'])?'Sipariş belgesi değiştirildi':'Sipariş belgesi eklendi'),$id,json_encode(['kind'=>$kind,'version'=>$version]),json_encode(['kind'=>$kind,'version'=>$version+1]),$now]);
                (new OperationalMailEvents($this->db,$this->cipher))->record($company,$id,['events'=>[$bytes===null?'document_deleted':'document_uploaded'],'provider'=>'portal','section'=>'orders','documentKind'=>$kind,'actorName'=>ClientSessionOperator::actorName($user),'orderId'=>$id],(int)$user['id']);
                return ['version'=>$version+1,'saved'=>true];
            });
            if($new&&isset($old['key'])&&$old['key']!==$new['key']||$bytes===null&&isset($old['key']))$this->files->remove($old['key']);
            return $result;
        }catch(\Throwable $error){if($new)$this->files->remove($new['key']);throw $error;}
    }
}

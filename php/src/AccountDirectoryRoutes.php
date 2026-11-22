<?php
declare(strict_types=1);
namespace Omnigo;

final class AccountDirectoryRoutes
{
    public function __construct(private Database $db){}
    public function handle(string $path,string $method,array $actor):void
    {
        if(preg_match('#^/api/account/portraits/(\d+)$#D',$path,$match)&&$method==='GET')(new AccountPortrait($this->db))->show($actor,(int)$match[1]);
        if(!str_starts_with($path,'/api/admin/users'))return;
        Security::admin($actor);$directory=new AccountDirectory($this->db);$writer=new AccountDirectoryWriter($this->db);
        if($path==='/api/admin/users'){
            if($method==='GET')Http::json($directory->listing($actor,$_GET));
            if($method==='POST')Http::json(['user'=>$writer->save($actor,null,Http::body())],201);
        }
        if(preg_match('#^/api/admin/users/(\d+)(?:/(portrait|sessions))?$#D',$path,$match)){
            $id=(int)$match[1];$action=$match[2]??'';
            if($action===''&&$method==='GET')Http::json(['user'=>AccountDirectory::publicRow($directory->row($id))]);
            if($action===''&&$method==='PUT')Http::json(['user'=>$writer->save($actor,$id,Http::body())]);
            if($action===''&&$method==='DELETE'){$writer->remove($actor,$id,Http::body());Http::json(['removed'=>true]);}
            if($action==='portrait'&&in_array($method,['POST','DELETE'],true)){
                $bytes=null;$data=$method==='DELETE'?Http::body():$_POST;
                if($method==='POST'){$file=$_FILES['portrait']??[];if(($file['error']??1)!==UPLOAD_ERR_OK||($file['size']??0)>2097152||!is_uploaded_file($file['tmp_name']??''))Http::fail(400,'En fazla 2 MB boyutunda bir profil resmi seçin.');$bytes=file_get_contents($file['tmp_name']);}
                Http::json(['user'=>(new AccountPortrait($this->db))->save($actor,$id,$data['revision']??null,$bytes)]);
            }
            if($action==='sessions'&&$method==='POST'){
                $data=Http::body();TransactionRetry::run($this->db,function()use($directory,$actor,$id,$data){$this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$id]);$directory->assertRevision($directory->row($id),$data['revision']??null);$directory->revoke($id);$directory->audit($actor,$id,'Açık oturumlar kapatıldı');});Http::json(['revoked'=>true]);
            }
        }
        Http::fail(405,'İşlem desteklenmiyor.');
    }
}

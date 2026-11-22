<?php
declare(strict_types=1);
namespace Omnigo;

final class SupportRoutes
{
    public function __construct(private Database $db,private Security $security){}
    public function handle(string $path,string $method,array $user): void
    {
        if(!str_starts_with($path,'/api/support/'))return;
        if($method!=='GET')ClientSessionOperator::requireName($user);
        $company=$this->security->company($user,$_GET['company']??null);$repo=new SupportRepository($this->db);
        if(!$repo->ready())Http::fail(503,'Destek alanı hazırlanıyor. Biraz sonra tekrar deneyin.');
        if($path==='/api/support/tickets'){
            if($method==='GET'){
                $page=filter_var($_GET['page']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>10000]]);
                if($page===false)Http::fail(400,'Sayfa geçersiz.');Http::json($repo->listing($user,$company,$page));
            }
            if($method==='POST')Http::json(['id'=>$repo->create($user,$company,Http::body())],201);
        }
        if(preg_match('#^/api/support/tickets/(\d+)(?:/(reply|status))?$#D',$path,$match)){
            $id=(int)$match[1];$action=$match[2]??'';
            if($method==='DELETE'&&$action===''){$repo->delete($user,$company,$id);Http::json(['message'=>'Destek talebi silindi.']);}
            if($method==='GET'&&$action===''){
                $before=filter_var($_GET['before']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
                if($before===false)Http::fail(400,'Mesaj aralığı geçersiz.');Http::json($repo->detail($user,$company,$id,$before));
            }
            if($method==='POST'&&$action==='reply'){$repo->reply($user,$company,$id,Http::body());Http::json(['message'=>'Mesajınız iletildi.']);}
            if($method==='POST'&&$action==='status'){$data=Http::body();$repo->status($user,$company,$id,Validation::choice($data['status']??null,['open','closed']));Http::json(['message'=>'Talep durumu güncellendi.']);}
        }
        Http::fail(405,'Destek işlemi desteklenmiyor.');
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

final class ChatRoutes
{
    public function __construct(private Database $db,private Security $security){}

    public function handle(string $path,string $method,array $user):void
    {
        if(!str_starts_with($path,'/api/chat/'))return;
        $chat=new ChatStore($this->db);
        if($path==='/api/chat/threads'){
            if($method==='GET')Http::json($chat->overview($user));
            if($method==='POST')Http::json(['id'=>$chat->create($user,Http::body())],201);
            if($method==='DELETE')Http::json(['dismissed'=>(new ChatDeletion($this->db))->threads($user,Http::body()['threads']??null)]);
        }
        if($path==='/api/chat/contacts'&&$method==='GET'){
            $company=isset($_GET['company'])?$this->security->company($user,$_GET['company']):null;
            Http::json(['contacts'=>(new ChatAccess($this->db))->contacts($user,$company)]);
        }
        if($path==='/api/chat/presence'&&$method==='POST'){$chat->presence($user);Http::json(['active'=>true]);}
        if(preg_match('#^/api/chat/threads/([1-9][0-9]{0,14})(?:/(messages|read)(?:/([1-9][0-9]{0,14}))?)?$#D',$path,$match)){
            $thread=(int)$match[1];$action=$match[2]??'';$message=(int)($match[3]??0);
            if($action===''&&$method==='GET'){
                $visible=($_GET['visible']??'')==='1';
                $from=$visible?self::cursor('knownFrom'):0;
                $to=$visible?self::cursor('knownTo'):0;
                if(($from===0)!==($to===0)||$from>$to)Http::fail(400,'Mesaj aralığı geçersiz.');
                Http::json($chat->detail($user,$thread,self::cursor('before'),$visible,$from,$to));
            }
            if($action==='messages'&&$method==='POST'&&!$message)Http::json(['id'=>$chat->send($user,$thread,Http::body())],201);
            if($action==='messages'&&$method==='GET'&&!$message)Http::json((new ChatDeletion($this->db))->availableMessages($user,$thread));
            if($action==='messages'&&$method==='DELETE'&&!$message)Http::json(['deleted'=>(new ChatDeletion($this->db))->messages($user,$thread,Http::body())]);
            if($action==='messages'&&$method==='DELETE'&&$message){$chat->delete($user,$thread,$message);Http::json(['deleted'=>true]);}
            if($action==='read'&&$method==='POST'&&!$message){
                $chat->read($user,$thread,Validation::integer(Http::body()['id']??null));Http::json(['read'=>true]);
            }
        }
        Http::fail(405,'Sohbet işlemi desteklenmiyor.');
    }

    private static function cursor(string $key):int
    {
        $value=filter_var($_GET[$key]??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>999999999999999]]);
        if($value===false)Http::fail(400,'Mesaj sırası geçersiz.');
        return $value;
    }
}

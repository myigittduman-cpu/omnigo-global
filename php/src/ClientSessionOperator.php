<?php
declare(strict_types=1);
namespace Omnigo;

/** An immutable, per-entry operator context, scoped to the authenticated session. */
final class ClientSessionOperator
{
    public function __construct(private Database $db){}
    public static function install(Database $db): void
    {
        $suffix=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $db->run('CREATE TABLE IF NOT EXISTS client_session_operators (token CHAR(64) PRIMARY KEY, session_token CHAR(64) NOT NULL, name VARCHAR(100) NOT NULL, created BIGINT NOT NULL)'.$suffix);
    }
    public static function name(mixed $value): string
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||preg_match('/[\p{Cc}\p{Cf}]/u',$value))Http::fail(400,'İşlem yapacak kişinin adını ve soyadını yazın.');
        $name=trim(preg_replace('/\s+/u',' ',$value));
        if(preg_match_all('/\p{L}/u',$name)<3)Http::fail(400,'Ad soyad alanına en az 3 harf yazın. Boşluk zorunlu değildir.');
        if(mb_strlen($name)>100||!preg_match("/^[\p{L}\p{M}]+(?:['’.-][\p{L}\p{M}]+)*(?: [\p{L}\p{M}]+(?:['’.-][\p{L}\p{M}]+)*)*$/uD",$name))Http::fail(400,'Ad soyad alanını yalnız harflerle yazın (en fazla 100 karakter).');
        return $name;
    }
    /** Compatibility endpoint for installed older clients; audit identity is the account. */
    public function start(array $user,mixed $name): array
    {
        if($user['role']!=='client')Http::fail(403,'Bu alan müşteri girişleri içindir.');
        return ['operatorToken'=>'','operatorName'=>$user['name']];
    }
    public function resolve(array $user,mixed $token): array
    {
        if($user['role']==='client')$user['operatorName']=$user['name'];return $user;
    }
    public static function requireName(array $user): void
    {
        if(empty($user['id'])||empty($user['name']))Http::fail(401,'Giriş yapmanız gerekiyor.');
    }
    public static function actorName(array $user,?string $legacyName=null): string
    {
        return $user['name']??'Müşteri kullanıcısı';
    }
}

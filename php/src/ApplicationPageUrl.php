<?php
declare(strict_types=1);
namespace Omnigo;

final class ApplicationPageUrl
{
    public static function validate(mixed $value): ?string
    {
        if($value===null||$value==='')return null;
        if(!is_string($value))Http::fail(400,'Bağlantı adresini kontrol edin.');
        $url=trim($value);
        if($url==='')return null;
        if(strlen($url)>500||preg_match('/[\x00-\x20\x7f\\\\]/',$url))Http::fail(400,'Bağlantı adresini kontrol edin.');
        if(str_starts_with($url,'/')&&!str_starts_with($url,'//'))return $url;
        $parts=parse_url($url);
        if(!filter_var($url,FILTER_VALIDATE_URL)||!is_array($parts)||strtolower($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))Http::fail(400,'Bağlantı adresi HTTPS veya uygulama içi / adresi olmalıdır.');
        return $url;
    }
}

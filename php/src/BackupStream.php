<?php
declare(strict_types=1);
namespace Omnigo;

/** Each bounded record is authenticated, ordered and bound to this archive. */
final class BackupStream
{
    public const HEADER = "OMNIGO-BACKUP-2\n";
    private const LIMIT = 33554432;

    public static function write(string $path, string $key, iterable $records): void
    {
        if (strlen($key)!==32) throw new \RuntimeException('Yedek anahtarı geçersiz.');
        $file=fopen($path,'xb');
        if (!$file) throw new \RuntimeException('Yedek dosyası oluşturulamadı.');
        chmod($path,0600);
        try {
            $salt=random_bytes(32); $derived=hash_hkdf('sha256',$key,32,'omnigo-backup-v2',$salt);
            self::put($file,self::HEADER.$salt); $index=0; $previous=str_repeat("\0",16);
            $frames=(function() use($records) { yield from $records; yield ['type'=>'end']; })();
            foreach ($frames as $record) {
                $json=json_encode($record,JSON_THROW_ON_ERROR);
                if (strlen($json)>self::LIMIT || $index>=0xffffffff) throw new \RuntimeException('Yedek kaydı sınırı aşıldı.');
                $sequence=pack('N',$index++); $nonce=str_repeat("\0",8).$sequence; $tag='';
                $cipher=openssl_encrypt(gzencode($json,6),'aes-256-gcm',$derived,OPENSSL_RAW_DATA,$nonce,$tag,self::HEADER.$salt.$sequence.$previous);
                if ($cipher===false || strlen($cipher)>self::LIMIT+65536) throw new \RuntimeException('Yedek kaydı şifrelenemedi.');
                self::put($file,pack('N',strlen($cipher)).$tag.$cipher); $previous=$tag;
            }
            if (!fflush($file) || (function_exists('fsync') && !fsync($file))) throw new \RuntimeException('Yedek diske yazılamadı.');
        } finally { fclose($file); }
    }

    public static function records(string $path, string $key): \Generator
    {
        if (strlen($key)!==32) throw new \RuntimeException('Yedek anahtarı geçersiz.');
        $file=fopen($path,'rb');
        if (!$file) throw new \RuntimeException('Yedek okunamadı.');
        try {
            if (self::take($file,strlen(self::HEADER))!==self::HEADER) throw new \RuntimeException('Yedek biçimi geçersiz.');
            $salt=self::take($file,32); $derived=hash_hkdf('sha256',$key,32,'omnigo-backup-v2',$salt);
            $index=0; $previous=str_repeat("\0",16);
            while (true) {
                $length=unpack('N',self::take($file,4))[1];
                if ($length<1 || $length>self::LIMIT+65536 || $index>=0xffffffff) throw new \RuntimeException('Yedek kaydı geçersiz.');
                $tag=self::take($file,16); $sequence=pack('N',$index++);
                $compressed=openssl_decrypt(self::take($file,$length),'aes-256-gcm',$derived,OPENSSL_RAW_DATA,str_repeat("\0",8).$sequence,$tag,self::HEADER.$salt.$sequence.$previous);
                if ($compressed===false) throw new \RuntimeException('Yedek doğrulanamadı.');
                $json=@gzdecode($compressed,self::LIMIT);
                if ($json===false) throw new \RuntimeException('Yedek açılamadı.');
                $record=json_decode($json,true,64,JSON_THROW_ON_ERROR); $previous=$tag;
                if (!is_array($record) || !isset($record['type'])) throw new \RuntimeException('Yedek kaydı geçersiz.');
                if ($record['type']==='end') {
                    if (fread($file,1)!=='') throw new \RuntimeException('Yedeğin sonuna veri eklenmiş.');
                    return;
                }
                yield $record;
            }
        } finally { fclose($file); }
    }

    public static function put($file, string $bytes): void
    {
        while ($bytes!=='') { $written=fwrite($file,$bytes); if (!$written) throw new \RuntimeException('Dosya yazılamadı.'); $bytes=substr($bytes,$written); }
    }
    private static function take($file, int $length): string
    {
        $bytes='';
        while (strlen($bytes)<$length) { $chunk=fread($file,$length-strlen($bytes)); if ($chunk===false || $chunk==='') throw new \RuntimeException('Yedek eksik.'); $bytes.=$chunk; }
        return $bytes;
    }
}

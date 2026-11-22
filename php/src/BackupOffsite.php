<?php
declare(strict_types=1);
namespace Omnigo;

/** Private transport files for authenticated cPanel retrieval, never public URLs. */
final class BackupOffsite
{
    public static function prepare(string $path, array $status, string $key, string $publicKey): string
    {
        $resource=openssl_pkey_get_public($publicKey); $details=$resource?openssl_pkey_get_details($resource):false;
        if (!$details || $details['type']!==OPENSSL_KEYTYPE_RSA || $details['bits']<3072) throw new \RuntimeException('Kurtarma açık anahtarı geçersiz.');
        if (!openssl_public_encrypt($key,$envelope,$resource,OPENSSL_PKCS1_OAEP_PADDING)) throw new \RuntimeException('Kurtarma anahtarı korunamadı.');
        $file=fopen($path,'rb'); if (!$file) throw new \RuntimeException('Yedek okunamadı.');
        $parts=[];
        try {
            while (!feof($file)) {
                // Fileman limits the encoded JSON file to 1 MiB, including base64 overhead.
                $bytes=fread($file,524288); if ($bytes===false) throw new \RuntimeException('Yedek parçası okunamadı.'); if ($bytes==='') break;
                $name=basename($path).'.part-'.sprintf('%06d',count($parts)).'.json';
                $content=json_encode(['data'=>base64_encode($bytes)],JSON_THROW_ON_ERROR);
                if (file_put_contents(dirname($path).'/'.$name,$content,LOCK_EX)!==strlen($content)) throw new \RuntimeException('Yedek aktarım parçası yazılamadı.');
                chmod(dirname($path).'/'.$name,0600); $parts[]=['file'=>$name,'bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
            }
        } finally { fclose($file); }
        $manifest=[...$status,'parts'=>$parts,'recovery'=>['algorithm'=>'RSA-OAEP-SHA1','keyId'=>hash('sha256',$details['key']),'envelope'=>base64_encode($envelope)]];
        $output=$path.'.transport.json'; $json=json_encode($manifest,JSON_THROW_ON_ERROR);
        if (file_put_contents($output.'.tmp',$json,LOCK_EX)!==strlen($json) || !rename($output.'.tmp',$output)) throw new \RuntimeException('Yedek aktarım bilgisi yazılamadı.');
        chmod($output,0600); return basename($output);
    }
}

<?php
declare(strict_types=1);
namespace Omnigo;

/** Format checks, not antivirus or content disarm. Files are private downloads only. */
final class ShipmentPdf
{
    public const MAX_BYTES=4194304;
    public const INVOICE_MAX_BYTES=20*1024*1024;

    public static function validate(string $bytes,int $maxBytes=self::MAX_BYTES): void
    {
        if(strlen($bytes)>$maxBytes)Http::fail(413,'PDF en fazla '.intdiv($maxBytes,1024*1024).' MB olabilir.');
        if(!preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)[\r\n]/',$bytes)||
            (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)!=='application/pdf'||
            !preg_match('/startxref\s+(\d+)\s+%%EOF\s*\z/s',substr($bytes,-2048),$end))
            Http::fail(415,'PDF okunamadı. Dosyayı yeniden PDF olarak kaydedip yükleyin.');
        $offset=(int)$end[1];
        if($offset<9||$offset>=strlen($bytes)||!preg_match('/\A(?:xref\b|\d+\s+\d+\s+obj\b)/',substr($bytes,$offset,80)))
            Http::fail(415,'PDF dosyası eksik veya bozuk.');
        // Reject common interactive/attached content; do not render uploaded PDFs in the portal.
        $names=preg_replace_callback('/#([a-fA-F0-9]{2})/',fn($m)=>chr(hexdec($m[1])),$bytes);
        if(preg_match('~/\s*(?:JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA|RichMedia|XFA|Encrypt)\b~',$names))
            Http::fail(415,'Şifreli, ek dosya veya etkin içerik barındıran PDF kabul edilmiyor. Yazdır → PDF olarak kaydet seçeneğiyle yeniden oluşturun.');
    }
}

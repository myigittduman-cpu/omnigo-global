<?php
declare(strict_types=1);
namespace Omnigo;

/** Safe diagnostics: never include provider bodies, credentials or exception text. */
final class PazaramaDiagnostics
{
    public static function failure(string $stage, \Throwable $error): array
    {
        $labels = [
            'authentication' => 'API yetkilendirmesi',
            'orders' => 'Sipariş listesinin okunması',
            'questions' => 'Ürün soru ve cevaplarının okunması',
            'preview' => 'Sipariş alanlarının doğrulanması',
        ];
        if (!isset($labels[$stage])) $stage = 'internal';
        $status = $error instanceof SyncFailure ? $error->httpStatus : 0;
        if ($status < 400 || $status > 599) $status = 0;
        $detail = match ($status) {
            401, 403 => 'Pazarama erişim yetkisini kabul etmedi. Mevcut API bilgilerinin ve izinlerinin kontrolü gerekiyor.',
            429 => 'Pazarama istek sınırı bildirdi. İzin verilen zamanda yeniden denenecek.',
            422 => 'Servis yanıtı beklenen alan veya biçim kontrolünü geçemedi.',
            502 => 'Servis yanıtı uygulamanın beklediği biçimde okunamadı.',
            503 => 'İstek tamamlanamadı. Servis yanıtı, ağ bağlantısı veya zaman aşımı kontrol edilmeli.',
            default => 'Bu aşamadaki işlem tamamlanamadı; sunucu kontrolü gerekiyor.',
        };
        return [
            'stage' => $stage,
            'code' => 'PZA-'.strtoupper($stage).'-'.$status,
            'message' => ($labels[$stage] ?? 'Yerel işlem').': '.$detail,
            // This is an application diagnostic code, not necessarily a remote HTTP response.
            'checkedAt' => gmdate('c'),
        ];
    }
}

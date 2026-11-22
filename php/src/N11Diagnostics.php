<?php
declare(strict_types=1);
namespace Omnigo;

final class N11Diagnostics
{
    public static function failure(\Throwable $error): array
    {
        $status=$error instanceof SyncFailure?$error->httpStatus:0;
        return ['code'=>'N11-READ-'.$status,'checkedAt'=>gmdate('c'),'message'=>match($status){
            401,403=>'n11 API anahtarını veya API şifresini kabul etmedi. Mevcut bağlantı bilgilerini kontrol edin.',
            429=>'n11 istek sınırı bildirdi. Kuyruk, belirtilen bekleme süresinden sonra tekrar deneyecek.',
            422=>'n11 yanıtı veri kontrolünü geçemedi. Tutarlar doğrulanana kadar müşteri satışlarına eklenmeyecek.',
            502=>'n11 yanıtı okunamadı; yeniden denenecek.',
            default=>'n11 okuma isteği tamamlanamadı. Kuyruk yeniden deneyecek.'}];
    }
}

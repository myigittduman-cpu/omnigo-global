<?php
declare(strict_types=1);
namespace Omnigo;

final class HepsiburadaDiagnostics
{
    public static function failure(\Throwable $error): array
    {
        $status=$error instanceof SyncFailure?$error->httpStatus:0;
        return ['code'=>'HB-READ-'.$status,'checkedAt'=>gmdate('c'),'message'=>match($status){
            401,403=>'Hepsiburada erişimi doğrulanamadı. Merchant ID, API kullanıcı adı, servis anahtarı ve entegratör yetkisini kontrol edin.',
            429=>'Hepsiburada istek sınırı bildirdi. Belirtilen süre sonunda yeniden denenecek.',
            422=>'Yanıt veya mağaza eşleşmesi doğrulanamadı. Bu kayıtlar satış toplamına eklenmedi.',
            default=>'Hepsiburada okuma isteği tamamlanamadı. Kuyruk yeniden deneyecek.'}];
    }
}

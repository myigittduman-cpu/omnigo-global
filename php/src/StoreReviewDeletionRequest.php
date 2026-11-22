<?php
declare(strict_types=1);
namespace Omnigo;

/** Review requests are inspectable by the sample administrator without sending real email. */
final class StoreReviewDeletionRequest
{
    public static function create(Database $db,array $actor): array
    {
        $user=$db->one('SELECT * FROM users WHERE id=?',[$actor['id']]);
        if(!$user)Http::fail(401,'Hesap bulunamadı.');
        $company=(int)($user['company_id']??$db->one('SELECT id FROM companies ORDER BY id LIMIT 1')['id']);
        $request=substr(hash('sha256','review-deletion:'.$user['id'].':'.gmdate('Ymd')),0,48);
        $ticket=(new SupportRepository($db))->create($user,$company,[
            'subject'=>'Örnek hesap silme talebi','category'=>'issue','requestId'=>$request,
            'body'=>'Bu örnek hesap için silme talebi akışı denendi. Gerçek müşteri verisi yoktur. Mağaza incelemesi boyunca yeniden giriş yapılabilmesi için örnek hesap açık tutulur.',
        ]);
        return ['requestId'=>'ORNEK-SILME-'.$ticket,'message'=>'Örnek silme talebiniz kaydedildi; örnek yönetici destek listesinde görülebilir. İnceleme hesabı yeniden kullanılabilmesi için açık tutulur. Gerçek hesaplara veya verilere işlem yapılmaz.'];
    }
}

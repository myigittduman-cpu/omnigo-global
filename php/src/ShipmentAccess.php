<?php
declare(strict_types=1);
namespace Omnigo;

final class ShipmentAccess
{
    public static function company(array $user,int $company): void
    {
        if($company>0&&(($user['role']??'')==='admin'||(($user['role']??'')==='client'&&(int)($user['company_id']??0)===$company)))return;
        Http::fail(403,'Bu firmanın sevkiyat kayıtlarına erişemezsiniz.');
    }
}

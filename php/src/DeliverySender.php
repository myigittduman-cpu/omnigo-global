<?php
declare(strict_types=1);
namespace Omnigo;

final class DeliverySender
{
    public static function forCompany(Database $db,int $company): array
    {
        $row=$db->one('SELECT name FROM companies WHERE id=?',[$company]);
        $profile=(new CustomerManagement($db))->profile($company,true);
        $name=$row['name']??'';
        // Sender details explicitly supplied with the user's delivery document.
        $vizyon=in_array($name,['Vizyon Beyaz Eşya','Vizyona','Vizyona Beyaz Eşya'],true);
        return ['name'=>$vizyon?'Vizyon Beyaz Eşya Mobilya Oto. Ve Kiralama Paz. Tic. Ltd. Şti.':$name,
            'phone'=>$vizyon?'0(554) 898 27 77':($profile['phone']??''),'hasLogo'=>$profile['hasLogo']];
    }
}

<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\{CustomerPermissionCatalog,CustomerTableVisibility,CustomerStandardPermissions};

function verifyStatus(bool $value,string $reason):void{
    if(!$value)throw new RuntimeException($reason);
}
$flag=CustomerTableVisibility::CANCELLED_STATUS;
$legacy=CustomerStandardPermissions::grants();
foreach(CustomerTableVisibility::STATUS_PAGES as $page){
    unset($legacy[$page][$flag]);
    $legacy[$page]['view']=true;
}
$merged=CustomerPermissionCatalog::completePages(array_replace_recursive(CustomerPermissionCatalog::defaults(),$legacy),$legacy);
foreach(CustomerTableVisibility::STATUS_PAGES as $page){
    $access=['pages'=>[$page=>'shown'],'grants'=>$merged];
    verifyStatus(CustomerTableVisibility::canSeeCancelledStatus($access,$page),'Eski operasyon kullanıcısı');
    verifyStatus(CustomerTableVisibility::fromAccess($access,$page)===CustomerTableVisibility::NONE,'Finansal ayrıntılar kapalı');
    $grants=$merged;$grants[$page][$flag]=false;
    $kept=CustomerTableVisibility::preserveOmitted($legacy,$grants);
    verifyStatus($kept[$page][$flag]===false,'Açıkça kapatılan işaret eski düzenleyicide korunmalı');
    $access['pages'][$page]='hidden';
    verifyStatus(!CustomerTableVisibility::canSeeCancelledStatus($access,$page),'Gizli menü');
}
verifyStatus(!in_array($flag,CustomerTableVisibility::actions('orders'),true),'Kapsam dışı sayfa');

<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Omnigo\CompanyLogoPresentation;
$image=imagecreatetruecolor(200,200);$white=imagecolorallocate($image,255,255,255);$gold=imagecolorallocate($image,190,135,30);imagefill($image,0,0,$white);imagefilledrectangle($image,30,80,170,110,$gold);
ob_start();imagepng($image);$original=ob_get_clean();$fitted=CompanyLogoPresentation::fit($original);$size=getimagesizefromstring($fitted);
if($size[0]!==147||$size[1]!==37)throw new RuntimeException('Logo boşluğu yanlış ölçüldü.');
if(CompanyLogoPresentation::fit('invalid')!=='invalid')throw new RuntimeException('Geçersiz logo korunmadı.');
imagedestroy($image);echo "Logo oranı, çevre boşluğu ve güvenli geri dönüş doğrulandı.\n";

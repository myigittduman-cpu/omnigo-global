<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/CompanyLogoPresentation.php';
use Omnigo\CompanyLogoPresentation;
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
function logo(array $background,array $ink,bool $transparent=false): string {
    $image=imagecreatetruecolor(300,300);imagealphablending($image,false);imagesavealpha($image,true);
    imagefill($image,0,0,imagecolorallocatealpha($image,...[...$background,$transparent?127:0]));
    imagefilledrectangle($image,30,120,269,179,imagecolorallocate($image,...$ink));
    ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);return $bytes;
}
foreach([
 'navy'=>[[2,17,42],[215,174,82],false],
 'white'=>[[255,255,255],[20,44,62],false],
 'transparent-white'=>[[0,0,0],[255,255,255],true],
] as $name=>[$background,$ink,$transparent]) {
    $source=logo($background,$ink,$transparent);$hash=hash('sha256',$source);
    $fitted=CompanyLogoPresentation::fit($source);$info=getimagesizefromstring($fitted);
    check($info[0]===248&&$info[1]===68,"$name kenar payı ayarlanamadı.");
    check(hash('sha256',$source)===$hash,"$name orijinali değişti.");
    $image=imagecreatefromstring($fitted);$center=imagecolorsforindex($image,imagecolorat($image,124,34));
    check([$center['red'],$center['green'],$center['blue']]===$ink,"$name logo rengi değişti.");
    imagedestroy($image);
}
$blank=logo([255,255,255],[255,255,255]);
check(CompanyLogoPresentation::fit($blank)===$blank,'Boş logo korunmadı.');
$photo=imagecreatetruecolor(300,300);
for($x=0;$x<300;$x++)imageline($photo,$x,0,$x,299,imagecolorallocate($photo,(int)($x*255/299),50,90));
ob_start();imagepng($photo);$original=ob_get_clean();imagedestroy($photo);
$info=getimagesizefromstring(CompanyLogoPresentation::fit($original));
check($info[0]===300&&$info[1]===300,'Değişken renkli kenarlar kırpıldı.');
check(CompanyLogoPresentation::fit('invalid')==='invalid','Bozuk dosya korunmadı.');
echo "Logo boşlukları, renkleri ve orijinal koruması doğrulandı.\n";

<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/CompanyLogoPresentation.php';
use Omnigo\CompanyLogoPresentation;

function checkBadge(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// Padding, asymmetry and the markers stand in for separated letters and a small top-right slogan.
foreach ([[480,130,false], [210,245,false], [90,270,true]] as [$width,$height,$transparent]) {
    $source = imagecreatetruecolor($width+40, $height+110);
    imagealphablending($source, false);
    imagesavealpha($source, true);
    $background = $transparent ? imagecolorallocatealpha($source,0,0,0,127) : imagecolorallocate($source,248,248,248);
    imagefill($source,0,0,$background);
    $mark = imagecolorallocate($source,15,140,45);
    imagefilledrectangle($source,20,65+(int)($height*.22),19+$width,64+(int)($height*.7),$mark);
    imagefilledrectangle($source,20+(int)($width*.78),65,19+$width,66+(int)($height*.10),$mark);
    imagefilledrectangle($source,20+(int)($width*.24),65+(int)($height*.82),19+(int)($width*.76),64+$height,$mark);
    ob_start(); imagepng($source); $bytes = (string)ob_get_clean();
    $originalHash = hash('sha256', $bytes);
    $output = imagecreatefromstring(CompanyLogoPresentation::paymentFrame($bytes));
    checkBadge(imagesx($output) === 600 && imagesy($output) === 240,'Ortak 5:2 ölçüsü yanlış.');
    checkBadge(imagecolorsforindex($output,imagecolorat($output,0,0))['alpha'] === 127,'Ovalin dışı saydam olmalı.');
    $rim = imagecolorsforindex($output,imagecolorat($output,300,2));
    checkBadge($rim['red'] === 199 && $rim['green'] === 170 && $rim['blue'] === 114,'Altın çerçeve eksik.');
    $left=600; $right=-1; $top=240; $bottom=-1;
    for ($y=0; $y<240; $y++) for ($x=0; $x<600; $x++) {
        $pixel = imagecolorsforindex($output,imagecolorat($output,$x,$y));
        if ($pixel['green'] < $pixel['red']+60 || $pixel['green'] < $pixel['blue']+50) continue;
        $dx = max(0, abs($x+.5-300)-180);
        $dy = $y+.5-120;
        checkBadge($dx*$dx+$dy*$dy < 116*116,'Yazının bir kısmı altın çizgiye değiyor.');
        $left=min($left,$x); $right=max($right,$x); $top=min($top,$y); $bottom=max($bottom,$y);
    }
    $visibleWidth = $right-$left+1; $visibleHeight = $bottom-$top+1;
    checkBadge($visibleHeight>0 && abs(($visibleWidth/$visibleHeight)/($width/$height)-1)<.025,'Marka veya slogan kesilmiş ya da uzamış.');
    checkBadge(max($visibleWidth/600,$visibleHeight/240)>.88,'Logo gereğinden küçük.');
    checkBadge(hash('sha256',$bytes)===$originalHash,'Kaynak logo değişti.');
    imagedestroy($output); imagedestroy($source);
}
echo "Üç farklı mağaza logosunda saydam oval, altın çerçeve, oran ve metin sınırları doğrulandı.\n";

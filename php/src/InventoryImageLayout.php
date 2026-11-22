<?php
declare(strict_types=1);
namespace Omnigo;

/** Match the approved square product frame: 1024px, white, baseline 930. */
final class InventoryImageLayout
{
    public static function normalize(string $bytes):string
    {
        // Decode/size/type validation is shared with manual uploads.
        $safe=InventoryImageFile::normalize($bytes);$source=imagecreatefromstring($safe);
        if(!$source)throw new \InvalidArgumentException('Ürün görseli okunamadı.');
        $w=imagesx($source);$h=imagesy($source);
        if(max($w,$h)<600){imagedestroy($source);throw new \DomainException('Yüksek çözünürlüklü ürün görseli bekleniyor.');}
        $flat=imagecreatetruecolor($w,$h);imagefill($flat,0,0,imagecolorallocate($flat,255,255,255));imagecopy($flat,$source,0,0,0,0,$w,$h);imagedestroy($source);
        $ratio=min(1,512/max($w,$h));$sw=max(1,(int)round($w*$ratio));$sh=max(1,(int)round($h*$ratio));$scan=imagecreatetruecolor($sw,$sh);imagecopyresampled($scan,$flat,0,0,0,0,$sw,$sh,$w,$h);
        $left=$sw;$top=$sh;$right=0;$bottom=0;
        for($y=0;$y<$sh;$y++)for($x=0;$x<$sw;$x++){$pixel=imagecolorat($scan,$x,$y);if(min(($pixel>>16)&255,($pixel>>8)&255,$pixel&255)<245){$left=min($left,$x);$top=min($top,$y);$right=max($right,$x);$bottom=max($bottom,$y);}}
        imagedestroy($scan);
        if($right<=$left||$bottom<=$top){imagedestroy($flat);throw new \DomainException('Ürünün görsel alanı doğrulanamadı.');}
        $left=max(0,(int)floor($left/$ratio)-4);$top=max(0,(int)floor($top/$ratio)-4);$right=min($w,(int)ceil(($right+1)/$ratio)+4);$bottom=min($h,(int)ceil(($bottom+1)/$ratio)+4);
        $cw=$right-$left;$ch=$bottom-$top;$scale=min(880/$cw,850/$ch);$dw=max(1,(int)round($cw*$scale));$dh=max(1,(int)round($ch*$scale));
        $canvas=imagecreatetruecolor(1024,1024);imagefill($canvas,0,0,imagecolorallocate($canvas,255,255,255));imagecopyresampled($canvas,$flat,(int)round((1024-$dw)/2),930-$dh,$left,$top,$dw,$dh,$cw,$ch);imagedestroy($flat);
        ob_start();imagepng($canvas,null,6);$result=ob_get_clean();imagedestroy($canvas);
        if(!is_string($result)||$result==='')throw new \RuntimeException('Ürün görseli hazırlanamadı.');return $result;
    }
}

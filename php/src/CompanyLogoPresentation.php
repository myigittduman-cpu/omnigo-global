<?php
declare(strict_types=1);
namespace Omnigo;

/** Fit the visible wordmark, without replacing or modifying the stored original. */
final class CompanyLogoPresentation
{
    private const FRAME_WIDTH = 600;
    private const FRAME_HEIGHT = 240;
    private const GOLD = [199, 170, 114];

    /** Build the complete store badge once for the screen, print, PDF and workbook. */
    public static function paymentFrame(string $original): string
    {
        if (!function_exists('imagecreatefromstring')) return $original;
        $source = @imagecreatefromstring($original);
        if (!$source) return $original;
        try {
            $width = imagesx($source);
            $height = imagesy($source);
            if ($width > 2048 || $height > 2048) return $original;
            $background = self::borderBackground($source, $width, $height);
            $outline = self::wordmarkOutline($source, $background);
            $output = imagecreatetruecolor(self::FRAME_WIDTH, self::FRAME_HEIGHT);
            try {
                $fill = $background ?? ['red'=>16, 'green'=>28, 'blue'=>44];
                imagefill($output, 0, 0, imagecolorallocate($output, $fill['red'], $fill['green'], $fill['blue']));
                if ($outline) {
                    [$scale, $x, $y] = self::wordmarkPlacement($outline);
                    imagecopyresampled($output, $source, $x, $y, 0, 0,
                        max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), $width, $height);
                }
                self::finishBadge($output);
                ob_start();
                imagepng($output, null, 8);
                return (string) ob_get_clean();
            } finally {
                imagedestroy($output);
            }
        } finally {
            imagedestroy($source);
        }
    }

    /** Horizontal bounds on each occupied scanline preserve small lettering in the curved ends. */
    private static function wordmarkOutline(\GdImage $image, ?array $background): array
    {
        $outline = [];
        $width = imagesx($image);
        $height = imagesy($image);
        for ($y = 0; $y < $height; $y++) {
            $first = $width;
            $last = -1;
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if ($color['alpha'] > 110 || ($background !== null && self::similar($color, $background, 26))) continue;
                $first = min($first, $x);
                $last = $x;
            }
            if ($last >= 0) $outline[] = [$y, $first, $last];
        }
        return $outline;
    }

    private static function wordmarkPlacement(array $outline): array
    {
        $left = min(array_column($outline, 1));
        $right = max(array_column($outline, 2));
        $top = $outline[0][0];
        $bottom = $outline[count($outline) - 1][0];
        $centerX = ($left + $right + 1) / 2;
        $centerY = ($top + $bottom + 1) / 2;
        // Five pixels of clearance includes the gold rule and its antialiasing.
        $radius = self::FRAME_HEIGHT / 2 - 5;
        $straight = (self::FRAME_WIDTH - self::FRAME_HEIGHT) / 2;
        $low = 0.0;
        $high = min(2 * ($straight + $radius) / ($right - $left + 1), 2 * $radius / ($bottom - $top + 1));
        $offset = 0.0;
        for ($pass = 0; $pass < 20; $pass++) {
            $scale = ($low + $high) / 2;
            $minY = -INF;
            $maxY = INF;
            foreach ($outline as [$y, $first, $last]) {
                $extent = max($centerX - $first, $last + 1 - $centerX) * $scale;
                $curveX = max(0.0, $extent - $straight);
                if ($curveX > $radius) { $minY = INF; break; }
                $cap = sqrt($radius ** 2 - $curveX ** 2);
                $minY = max($minY, -$cap - ($y - $centerY) * $scale);
                $maxY = min($maxY, $cap - ($y + 1 - $centerY) * $scale);
                if ($minY > $maxY) break;
            }
            if ($minY <= $maxY) {
                $low = $scale;
                $offset = min($maxY, max($minY, 0.0));
            } else {
                $high = $scale;
            }
        }
        return [$low, (int) round(self::FRAME_WIDTH/2 - $centerX*$low), (int) round(self::FRAME_HEIGHT/2 + $offset - $centerY*$low)];
    }

    /** Keep a true alpha channel and a soft one-pixel edge at any output scale. */
    private static function finishBadge(\GdImage $image): void
    {
        $width = self::FRAME_WIDTH;
        $height = self::FRAME_HEIGHT;
        $radius = $height / 2;
        $straight = ($width - $height) / 2;
        $stroke = 4.0;
        imagealphablending($image, false);
        imagesavealpha($image, true);
        [$red,$green,$blue] = self::GOLD;
        for ($y = 0; $y < $height; $y++) {
            $dy = abs($y + .5 - $radius);
            for ($x = 0; $x < $width; $x++) {
                $dx = max(0.0, abs($x + .5 - $width/2) - $straight);
                $inset = $radius - .65 - sqrt($dx*$dx + $dy*$dy);
                if ($inset >= $stroke+.5) continue;
                if ($inset <= -.5) {
                    imagesetpixel($image, $x, $y, 127 << 24);
                    continue;
                }
                $alpha = (int) round(127 * min(1.0, max(0.0, .5 - $inset)));
                $ink = min(1.0, $stroke+.5 - $inset);
                $pixel = imagecolorat($image, $x, $y);
                $r = (int) round(($pixel >> 16 & 255)*(1-$ink) + $red*$ink);
                $g = (int) round(($pixel >> 8 & 255)*(1-$ink) + $green*$ink);
                $b = (int) round(($pixel & 255)*(1-$ink) + $blue*$ink);
                imagesetpixel($image, $x, $y, ($alpha << 24) | ($r << 16) | ($g << 8) | $b);
            }
        }
    }

    public static function fit(string $original): string
    {
        if(!function_exists('imagecreatefromstring'))return $original;
        $image=@imagecreatefromstring($original);if(!$image)return $original;
        try{
            $width=imagesx($image);$height=imagesy($image);
            if($width>2048||$height>2048)return $original;
            // A uniform opaque border is presentation padding as well. Keep
            // transparent white artwork and nonuniform backgrounds intact.
            $background=self::borderBackground($image,$width,$height);
            $left=$width;$top=$height;$right=-1;$bottom=-1;
            for($y=0;$y<$height;$y++)for($x=0;$x<$width;$x++){
                $color=imagecolorsforindex($image,imagecolorat($image,$x,$y));
                if($color['alpha']>110||($background!==null&&self::similar($color,$background,26)))continue;
                $left=min($left,$x);$right=max($right,$x);$top=min($top,$y);$bottom=max($bottom,$y);
            }
            if($right<0)return $original;
            $pad=max(3,(int)ceil(max($right-$left+1,$bottom-$top+1)*.015));
            $left=max(0,$left-$pad);$top=max(0,$top-$pad);$right=min($width-1,$right+$pad);$bottom=min($height-1,$bottom+$pad);
            $fitted=imagecrop($image,['x'=>$left,'y'=>$top,'width'=>$right-$left+1,'height'=>$bottom-$top+1]);
            if(!$fitted)return $original;
            try{imagesavealpha($fitted,true);ob_start();imagepng($fitted,null,8);return ob_get_clean();}
            finally{imagedestroy($fitted);}
        }finally{imagedestroy($image);}
    }

    private static function borderBackground(\GdImage $image,int $width,int $height): ?array
    {
        $inset=min(4,(int)floor(min($width,$height)/16));
        $corners=array_map(static fn(array $point):array=>imagecolorsforindex($image,imagecolorat($image,...$point)),
            [[$inset,$inset],[$width-1-$inset,$inset],[$inset,$height-1-$inset],[$width-1-$inset,$height-1-$inset]]);
        foreach($corners as $color)if($color['alpha']>100||!self::similar($color,$corners[0],14))return null;
        return $corners[0];
    }

    private static function similar(array $color,array $background,int $tolerance): bool
    {
        return max(abs($color['red']-$background['red']),abs($color['green']-$background['green']),
            abs($color['blue']-$background['blue']))<=$tolerance;
    }
}

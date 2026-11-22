<?php
require dirname(__DIR__).'/src/CompanyLogoPresentation.php';
use Omnigo\CompanyLogoPresentation;
if(!function_exists('imagecreatetruecolor'))throw new RuntimeException('GD gerekiyor.');
foreach([[480,120],[180,180],[100,280]] as [$width,$height]){
 $image=imagecreatetruecolor($width,$height);imagefill($image,0,0,imagecolorallocate($image,16,28,44));
 imagefilledrectangle($image,6,6,$width-7,$height-7,imagecolorallocate($image,220,180,80));
 ob_start();imagepng($image);$original=ob_get_clean();imagedestroy($image);$hash=hash('sha256',$original);
 $result=CompanyLogoPresentation::paymentFrame($original);$output=imagecreatefromstring($result);
 if(imagesx($output)!==600||imagesy($output)!==240||hash('sha256',$original)!==$hash)throw new RuntimeException('Logo boyutu veya özgün dosya bozuldu.');
 $found=0;
 for($y=0;$y<240;$y++)for($x=0;$x<600;$x++){
  $pixel=imagecolorsforindex($output,imagecolorat($output,$x,$y));if($pixel['red']<100)continue;
  $dx=max(0,abs($x-299.5)-180);$dy=abs($y-119.5);
  if($dx*$dx+$dy*$dy>120*120)throw new RuntimeException('Logo oval dışında kaldı.');$found++;
 }
 if($found<2000)throw new RuntimeException('Logo küçüldü.');imagedestroy($output);
}
echo "Ödeme logosu çerçevesi: 3 oran, kesilme ve özgün dosya koruması başarılı.\n";

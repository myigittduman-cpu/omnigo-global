<?php
define('ABSPATH',__DIR__);
function esc_url(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
function esc_attr(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
require __DIR__.'/../omnigo-blog/src/SocialLinks.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach([['og-footer-v2','og-f-brand'],['ogb-footer','ogb-footer-brand']] as [$footer,$brand]){
 $html='<main><a href="/gorusme-talebi/">İletişim</a></main><footer class="'.$footer.'"><div class="'.$brand.'"><a href="/">Logo</a><p>Marka açıklaması</p></div><nav><a href="/blog/">Blog</a><a href="https://www.instagram.com/omnigoglobal/" target="_blank" rel="noopener noreferrer">Instagram ↗</a></nav></footer>';
 $result=\Omnigo\Blog\SocialLinks::update($html);
 check(substr_count($result,'class="og-social-links"')===1&&substr_count($result,'<svg ')===3,'Üç logo, tek grup');
 check(!str_contains($result,'Instagram ↗')&&str_contains($result,'href="/blog/"'),'Eski metin kalkar, menü korunur');
 check(str_contains($result,'<p>Marka açıklaması</p><div class="og-social-links"'),'Marka açıklamasının altında');
 check(\Omnigo\Blog\SocialLinks::update($result)===$result,'Tekrarlı filtrede çoğalma yok');
 foreach(['https://www.instagram.com/omnigoglobal/','https://x.com/omnigoglobal','https://www.linkedin.com/in/omnigo-global-11b7aa444/'] as $url)check(str_contains($result,'href="'.$url.'"'),'Doğru profil bağlantısı');
 check(substr_count($result,'rel="noopener noreferrer"')===3&&substr_count($result,'yeni sekmede açılır')===3,'Güvenli ve erişilebilir dış bağlantı');
}
$other='<footer class="other"><div class="og-f-brand">Diğer</div></footer>';check(\Omnigo\Blog\SocialLinks::update($other)===$other,'İlgisiz alt bölüm korunur');
echo "Sosyal bağlantılar: iki alt bölüm, üç doğru profil/logosu, erişilebilirlik, tekrarsız çıktı ve menü koruması geçti.\n";

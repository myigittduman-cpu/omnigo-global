<?php
// No database or WordPress installation is touched by these navigation regression checks.
define('ABSPATH', __DIR__);
function is_admin(): bool { return $GLOBALS['admin'] ?? false; }
function home_url(string $path): string { return 'https://omnigoglobal.com' . $path; }
function esc_url(string $url): string { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
require __DIR__ . '/../omnigo-blog/src/Navigation.php';
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$original = '<nav class="og-links"><a href="/?page_id=2852">İş Birliği Modelimiz</a><a href="/?page_id=2854">Hakkımızda</a><a href="/?page_id=2856">İletişim</a><a href="/musteri-portali/" aria-current="page">Müşteri Portalı <span class="og-soon-tag">Hazırlanıyor</span></a></nav>';
$result = \Omnigo\Blog\Navigation::update($original);
check(strpos($result, 'Müşteri Portalı') < strpos($result, 'Hakkımızda'), 'Portal sırası yanlış');
check(str_contains($result, 'href="https://omnigoglobal.com/is-birligi-modelimiz/"'), 'Kalıcı bağlantı oluşturulmadı');
check(str_contains($result, 'aria-current="page"'), 'Erişilebilirlik niteliği kayboldu');
check(!str_contains($result, 'Hazırlanıyor'), 'Eski etiket kalmış');
check(substr_count($result, '>Blog</a>') === 1, 'Blog bağlantısı tekrarlı');
check(\Omnigo\Blog\Navigation::update($result) === $result, 'İkinci filtre geçişi menüyü değiştiriyor');
$other = '<nav class="unrelated"><a href="/x">Hakkımızda</a></nav>';
check(\Omnigo\Blog\Navigation::update($other) === $other, 'İlgisiz menü değişti');
$footer = \Omnigo\Blog\Navigation::update('<nav aria-label="Alt menü kurumsal"><a href="/">Ana Sayfa</a></nav>');
check(str_contains($footer, '/musteri-portali/') && str_contains($footer, '/gizlilik-ve-kisisel-veriler/'), 'Alt menü bağlantıları eksik');
check(\Omnigo\Blog\Navigation::update($footer) === $footer, 'Alt menü tekrarlanıyor');
$external = '<a href="https://example.com/?page_id=2852">Dış bağlantı</a>';
check(\Omnigo\Blog\Navigation::update($external) === $external, 'Dış site adresi değişti');
$GLOBALS['admin'] = true;
check(\Omnigo\Blog\Navigation::update($original) === $original, 'Yönetim içeriği değişti');
echo "11 menü koruma ve sıralama kontrolü geçti.\n";

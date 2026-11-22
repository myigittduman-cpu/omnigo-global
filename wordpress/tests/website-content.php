<?php
define('ABSPATH', __DIR__);
require __DIR__ . '/../omnigo-blog/src/SiteContent.php';
$source = file_get_contents(__DIR__ . '/fixtures/opening-animation.html');
$result = \Omnigo\Blog\SiteContent::removeIntro($source);
if ($source === $result || str_contains($result, 'og-brand-intro')) throw new RuntimeException('Açılış animasyonu tam kaldırılmadı.');
preg_match_all('~<script\b[^>]*>.*?</script>~s', $source, $before);
preg_match_all('~<script\b[^>]*>.*?</script>~s', $result, $after);
$removed = array_values(array_diff($before[0], $after[0]));
if (count($removed) !== 1 || !str_contains($removed[0], 'og-brand-intro-script')) throw new RuntimeException('İlgisiz script değişti.');
if (\Omnigo\Blog\SiteContent::removeIntro($result) !== $result) throw new RuntimeException('İkinci işlem içeriği değiştiriyor.');
echo "Gerçek ana sayfa üzerinde animasyon kaldırma ve diğer scriptleri koruma kontrolü geçti.\n";

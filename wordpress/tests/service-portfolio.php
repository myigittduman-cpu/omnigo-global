<?php
define('ABSPATH', __DIR__);
define('Omnigo\Blog\ROOT', dirname(__DIR__) . '/omnigo-blog');
function is_admin(): bool { return false; }
function is_front_page(): bool { return ($GLOBALS['route'] ?? '') === 'home'; }
function is_page($name = null): bool { return $name === 'uzmanlik-alanlarimiz' && ($GLOBALS['route'] ?? '') === 'services'; }
function home_url(string $path): string { return 'https://omnigoglobal.com' . $path; }
function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
require dirname(__DIR__) . '/omnigo-blog/src/ServicePortfolio.php';
require dirname(__DIR__) . '/omnigo-blog/src/Navigation.php';
require dirname(__DIR__) . '/omnigo-blog/src/SitePages.php';
$folder = dirname(__DIR__, 2) . '/verification/website-references-20261022';
foreach (['home', 'services'] as $route) {
    $GLOBALS['route'] = $route;
    \Omnigo\Blog\ServicePortfolio::prepare();
    $original = file_get_contents($folder . '/before/' . $route . '.html');
    // The production content filter runs before PageLayout appends og-compact.
    $original = str_replace('class="og-site og-compact"', 'class="og-site"', $original);
    $result = \Omnigo\Blog\Navigation::update(\Omnigo\Blog\ServicePortfolio::update($original));
    // Exercise the actual WordPress output-handler lifecycle, not just direct method calls.
    ob_start();
    ob_start([\Omnigo\Blog\ServicePortfolio::class, 'update']);
    echo $original;
    ob_end_flush();
    $buffered = ob_get_clean();
    if ($buffered !== \Omnigo\Blog\ServicePortfolio::update($original)) throw new RuntimeException('WordPress çıktı tamponu uyumsuz.');
    if (substr_count($result, 'id="og-digital-services"') !== 1 || substr_count($result, 'id="ogp-reference-title"') !== 1) throw new RuntimeException('Yeni bölüm eksik veya tekrarlı.');
    if (\Omnigo\Blog\ServicePortfolio::update($result) !== $result || \Omnigo\Blog\Navigation::update($result) !== $result) throw new RuntimeException('Tekrarlanan filtre içeriği değiştiriyor.');
    foreach (['e-ticaret-sitesi', 'mobil-uygulama', 'kurumsal-web'] as $id) if (!str_contains($result, 'id="' . $id . '"')) throw new RuntimeException('Hizmet bağlantısı eksik.');
    preg_match_all('~<script\b[^>]*>.*?</script>~su', $original, $oldScripts);
    preg_match_all('~<script\b[^>]*>.*?</script>~su', $result, $newScripts);
    if ($oldScripts !== $newScripts) throw new RuntimeException('Mevcut sayfa davranışı değişti.');
    foreach (['kurulum','yonetim','icerik','analiz'] as $id) if (str_contains($original, 'id="' . $id . '"') && !str_contains($result, 'id="' . $id . '"')) throw new RuntimeException('Mevcut hizmet kaldırıldı.');
    file_put_contents($folder . '/preview-' . $route . '.html', str_replace('</head>', '<link rel="stylesheet" href="/portfolio.css"></head>', $result));
}
$GLOBALS['route'] = 'other';
$sample = '<div class="og-site"><main><p>Başka sayfa</p></main><footer class="og-footer-v2"></footer></div>';
if (\Omnigo\Blog\ServicePortfolio::update($sample) !== $sample) throw new RuntimeException('İlgisiz sayfaya hizmet eklendi.');
$references = file_get_contents(dirname(__DIR__) . '/omnigo-blog/templates/references.php');
foreach (['portal.omnigoglobal','yonetim.omnigoglobal','api/','iframe','₺','hakediş','vergi','@','screenshot','uploads/'] as $private) if (str_contains(mb_strtolower($references), $private)) throw new RuntimeException('Referans sayfasında özel veri veya ekran kaynağı var: ' . $private);
if (!str_contains($references, "plugins_url('assets/references/'") || substr_count($references, '<img ') !== 6) throw new RuntimeException('Referans görselleri izin listesine uymuyor.');
if (substr_count($references, 'https://') !== 1 || !str_contains($references, 'https://www.vizyonagrup.com/')) throw new RuntimeException('Referansın dış bağlantıları izin listesine uymuyor.');
echo "Hizmet ekleme, mevcut içerik/script koruma, menü ve referans gizlilik kontrolleri geçti.\n";

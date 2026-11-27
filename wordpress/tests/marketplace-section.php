<?php
define('ABSPATH', __DIR__);
define('Omnigo\Blog\ROOT', dirname(__DIR__) . '/omnigo-blog');
define('Omnigo\Blog\VERSION', 'test');
function is_admin(): bool { return false; }
function is_feed(): bool { return false; }
function is_preview(): bool { return false; }
function is_front_page(): bool { return ($GLOBALS['route'] ?? '') === 'home'; }
function is_page($name): bool { return $name === ($GLOBALS['route'] ?? ''); }
function home_url($path): string { return 'https://omnigoglobal.com' . $path; }
function plugins_url($path, $file): string { return 'https://omnigoglobal.com/wp-content/plugins/omnigo-blog/' . $path; }
function esc_url($value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function add_action($name, $callback, $priority = 10): void { $GLOBALS['hooks'][$name] = $callback; }
function wp_enqueue_style(...$args): void { $GLOBALS['styles'][] = $args; }
require dirname(__DIR__) . '/omnigo-blog/src/MarketplaceSection.php';
use Omnigo\Blog\MarketplaceSection;
MarketplaceSection::boot();
$original = '<div class="og-site"><main><h1>Mevcut başlık</h1><script>const existing=true;</script></main><footer class="og-footer-v2">Mevcut alt alan</footer></div>';
foreach (['home', 'uzmanlik-alanlarimiz', 'hakkimizda'] as $route) {
    $GLOBALS['route'] = $route;
    ob_start();
    $GLOBALS['hooks']['template_redirect']();
    echo $original;
    if (MarketplaceSection::active()) ob_end_flush();
    $result = ob_get_clean();
    if (!MarketplaceSection::active()) {
        if ($result !== $original) throw new RuntimeException('İlgisiz sayfa değişti.');
        continue;
    }
    if (substr_count($result, 'id="pazaryerleri"') !== 1 || substr_count($result, '<li>') !== 8) throw new RuntimeException('Bölüm veya logo sayısı yanlış.');
    if (!str_contains($result, '<h1>Mevcut başlık</h1><script>const existing=true;</script>') || !str_contains($result, '<footer class="og-footer-v2">Mevcut alt alan</footer>')) throw new RuntimeException('Mevcut içerik değişti.');
    if (MarketplaceSection::insert($result, 'duplicate') !== $result) throw new RuntimeException('Bölüm tekrarlandı.');
    foreach (['Trendyol', 'Hepsiburada', 'n11', 'Pazarama', 'idefix', 'PTT AVM', 'Koçtaş', 'Teknosa'] as $name) if (!str_contains($result, $name)) throw new RuntimeException('Pazaryeri eksik.');
}
echo "Pazaryerleri bölümü: iki sayfa, çıktı tamponu ve mevcut içerik koruma kontrolleri başarılı.\n";

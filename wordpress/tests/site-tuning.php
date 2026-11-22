<?php
namespace {
    define('ABSPATH', __DIR__);
    $state = []; $removed = [];
    function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
    function is_admin() { return $GLOBALS['state']['admin'] ?? false; }
    function wp_doing_ajax() { return false; }
    function is_preview() { return $GLOBALS['state']['preview'] ?? false; }
    function is_customize_preview() { return false; }
    function get_queried_object() { return (object) ['ID' => 1, 'post_content' => $GLOBALS['state']['content'] ?? '']; }
    function get_post_meta(...$args) { return $GLOBALS['state']['builder'] ?? ''; }
    function apply_filters(...$args) { return $GLOBALS['state']['keep'] ?? false; }
    function wp_dequeue_style($handle) { $GLOBALS['removed'][] = $handle; }
    function wp_dequeue_script($handle) { $GLOBALS['removed'][] = $handle; }
    function is_404() { return false; }
    function is_singular(...$args) { return false; }
    function get_query_var($key) { return 1; }
    function get_post_type_archive_link($type) { return 'https://omnigoglobal.com/blog/'; }
    function get_pagenum_link($page, $escape = true) {
        $url = 'https://omnigoglobal.com/blog/?arama=stok&kategori=musteri-portali';
        return $escape ? str_replace('&', '&#038;', $url) : $url;
    }
    function rewrite_query($url, array $changes) {
        $parts = parse_url($url); parse_str($parts['query'] ?? '', $query);
        foreach ($changes as $key => $value) { if ($value === null) unset($query[$key]); else $query[$key] = $value; }
        return $parts['scheme'] . '://' . $parts['host'] . $parts['path'] . ($query ? '?' . http_build_query($query) : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
    function remove_query_arg($keys, $url) { return rewrite_query($url, array_fill_keys($keys, null)); }
    function add_query_arg($key, $value, $url) { return rewrite_query($url, [$key => $value]); }
}
namespace Omnigo\Blog {
    final class SitePages { public static function details() { return $GLOBALS['state']['known'] ?? true; } }
    final class Frontend {
        public static function active() { return $GLOBALS['state']['blog'] ?? false; }
        public static function parameter($key) { return $GLOBALS['state']['params'][$key] ?? ''; }
    }
    final class PortalPage { public static function active() { return false; } }
    final class ContentType { const POST_TYPE = 'omnigo_article'; }
    final class Images { public static function url(...$args) { return ''; } }
}
namespace {
    require __DIR__ . '/../omnigo-blog/src/PageLayout.php';
    require __DIR__ . '/../omnigo-blog/src/PublicAssets.php';
    require __DIR__ . '/../omnigo-blog/src/Seo.php';
    $fixtures = dirname(__DIR__, 2) . '/verification/site-review-2026-10-14-final/';
    foreach (['home', 'uzmanlik-alanlarimiz', 'omnigo-yaklasimi', 'is-birligi-modelimiz', 'hakkimizda', 'iletisim', 'gorusme-talebi'] as $page) {
        $before = file_get_contents($fixtures . $page . '.html');
        $path = $page === 'home' ? '//' : '/' . $page . '/';
        $after = \Omnigo\Blog\PageLayout::update($before, $path);
        check(preg_match_all('~<main\b~i', $after) === 1, "$page: ana içerik alanı tek değil");
        check(preg_match_all('~</main>~i', $after) === 1, "$page: kapanış etiketi hatalı");
        foreach (['~<script\b[^>]*>.*?</script>~s', '~<a\b[^>]*>~s', '~<(?:input|form|textarea|button)\b[^>]*>~s', '~\bid=["\'][^"\']+["\']~'] as $pattern) {
            preg_match_all($pattern, $before, $a); preg_match_all($pattern, $after, $b);
            check($a[0] === $b[0], "$page: bağlantı, kimlik, form veya script değişti");
        }
        check(\Omnigo\Blog\PageLayout::update($after, $path) === $after, "$page: ikinci işlem değişiklik yapıyor");
        if (in_array($page, ['home', 'uzmanlik-alanlarimiz'])) check(strlen($after) < strlen($before), "$page: metinler kısalmadı");
    }
    $unrelated = '<main id="main" class="site-main"><p>Dokunma</p></main><!-- #main -->';
    check(\Omnigo\Blog\PageLayout::update($unrelated, '/') === $unrelated, 'İlgisiz şablon değişti');
    foreach ([[], ['admin' => true], ['preview' => true], ['known' => false], ['content' => '[latepoint_book_button]'], ['builder' => '{"widgetType":"latepoint-booking-form"}'], ['keep' => true]] as $case) {
        $state = $case; $removed = [];
        \Omnigo\Blog\PublicAssets::trimBookingAssets();
        check($removed === ($case === [] ? ['latepoint-main-front', 'latepoint-main-front', 'latepoint-vendor-front'] : []), 'Randevu dosyası koruma koşulu hatalı');
    }
    foreach ([['arama' => 'stok', 'kategori' => 'musteri-portali'], ['kategori' => 'musteri-portali'], []] as $params) {
        $state = ['known' => false, 'blog' => true, 'params' => $params];
        $url = \Omnigo\Blog\Seo::data()['url'];
        $expected = 'https://omnigoglobal.com/blog/' . ($params ? '?' . http_build_query($params) : '');
        check($url === $expected, 'Canonical sorgu parametreleri bozuldu: ' . $url);
    }
    echo "7 gerçek sayfa: tek main, korunan bağlantı/form/scriptler, randevu korumaları ve 3 canonical senaryosu geçti.\n";
}

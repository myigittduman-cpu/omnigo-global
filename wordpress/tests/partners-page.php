<?php
namespace {
    define('ABSPATH', __DIR__);
    define('Omnigo\Blog\ROOT', __DIR__ . '/../omnigo-blog');
    function is_admin() { return false; }
    function is_feed() { return false; }
    function is_preview() { return false; }
    function is_404() { return $GLOBALS['wp_query']->is_404; }
    function home_url($path) { return 'https://omnigoglobal.com' . $path; }
    function status_header($status) { $GLOBALS['status'] = $status; }
    function add_action($hook, $callback, $priority = 10) { $GLOBALS['hooks'][$hook] = $callback; }
    function add_filter($hook, $callback, $priority = 10) { add_action($hook, $callback, $priority); }
}
namespace Omnigo\Blog {
    class SitePages {
        public static function path() { return $GLOBALS['path']; }
        public static function details() { return null; }
    }
    class Images { public static function url(...$args) { return 'https://example.test/image.jpg'; } }
}
namespace {
    require __DIR__ . '/../omnigo-blog/src/PartnersPage.php';
    require __DIR__ . '/../omnigo-blog/src/Seo.php';
    \Omnigo\Blog\PartnersPage::boot();
    $wp_query = (object) ['is_404' => true];
    $path = '/is-ortaklarimiz/';
    $GLOBALS['hooks']['wp']();
    if ($wp_query->is_404 || $status !== 200) throw new \RuntimeException('Yeni sayfa 404 olarak kaldı.');
    $template = $GLOBALS['hooks']['template_include']('original.php');
    if (!str_ends_with($template, '/templates/partners.php') || !is_file($template)) throw new \RuntimeException('Sayfa şablonu bulunamadı.');
    $meta = \Omnigo\Blog\Seo::data();
    if ($meta['url'] !== home_url($path) || !str_contains($meta['title'], 'İş Ortaklarımız')) throw new \RuntimeException('Başlık veya canonical yanlış.');
    $path = '/uzmanlik-alanlarimiz/';
    if ($GLOBALS['hooks']['template_include']('original.php') !== 'original.php' || \Omnigo\Blog\PartnersPage::details() !== null) throw new \RuntimeException('Mevcut sayfa etkileniyor.');
    echo "İş Ortaklarımız: yönlendirme, HTTP durumu, şablon ve arama motoru bilgileri doğrulandı.\n";
}

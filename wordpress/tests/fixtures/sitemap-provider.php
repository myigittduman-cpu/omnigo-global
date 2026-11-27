<?php
namespace {
    define('ABSPATH', __DIR__);
    define('Omnigo\\Blog\\ROOT', __DIR__ . '/../../omnigo-blog');
    $scenario = $argv[1] ?? 'warm';
    $built = 0;
    $entries = in_array($scenario, ['cold', 'cron'], true) ? [] : [['link' => 'https://example.test/', 'updated' => '2026-10-14']];
    if ($scenario === 'duplicate') foreach (['gizlilik-ve-kisisel-veriler', 'referanslarimiz', 'is-ortaklarimiz'] as $slug) $entries[] = ['link' => 'https://example.test/' . $slug . '/', 'updated' => '2026-10-14'];
    function get_query_var($key) { return $GLOBALS['scenario'] === 'other' ? 'taxonomy-type' : 'post-type'; }
    function get_option($key) { return $GLOBALS['scenario'] !== 'private'; }
    function apply_filters($key, $value) { return $value; }
    function home_url($path) { return 'https://example.test' . $path; }
}
namespace SureRank\Inc\Functions {
    class Cache {
        public static function get_file($path) { return str_ends_with($path, '-chunk-1.json') && $GLOBALS['entries'] ? json_encode($GLOBALS['entries']) : false; }
    }
}
namespace SureRank\Inc\Sitemap\Providers {
    class Registry {
        public static function get_instance() { return new self(); }
        public function for_request($type, $prefix) { return $this; }
        public function ensure_page_built($type, $prefix, $page) { $GLOBALS['built']++; $GLOBALS['entries'] = [['link' => 'https://example.test/', 'updated' => '2026-10-14']]; }
    }
}
namespace SureRank\Inc\Sitemap {
    class Generation_Mode { public static function allows_inline_build() { return $GLOBALS['scenario'] !== 'cron'; } }
    class Utils {
        public static function sitemap_main($entries) { return json_encode(['urls' => array_column($entries, 'link'), 'built' => $GLOBALS['built']]); }
        public static function strip_leading_noise($xml) { return $xml; }
        public static function output_headers() { echo "XML\n"; }
    }
}
namespace SureRank\Inc\BatchProcess { class Sync_Archives {} }
namespace Omnigo\Blog { class SitePages { const PRIVACY = '/gizlilik-ve-kisisel-veriler/'; const REFERENCES = '/referanslarimiz/'; } }
namespace {
    require __DIR__ . '/../../omnigo-blog/src/Sitemap.php';
    \Omnigo\Blog\Sitemap::pageArchive('page', 1, 200);
    echo json_encode(['fallback' => true, 'built' => $built]);
}

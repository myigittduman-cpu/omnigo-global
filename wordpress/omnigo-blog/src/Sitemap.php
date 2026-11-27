<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class Sitemap
{
    public static function boot(): void
    {
        add_filter('surerank_sitemap_enabled_cpts', static fn(array $types): array => array_filter($types, static fn($type): bool => !in_array($type->name ?? '', ['elementor-hf', 'elementor_library', 'sureforms_form'], true)));
        add_action('surerank_sitemap_before_generation', [self::class, 'pageArchive'], 10, 3);
    }

    // Preserve the provider's published/noindex filtering and cache lifecycle.
    public static function pageArchive(string $type, int $page, int $threshold): void
    {
        if ($type !== 'page' || $page !== 1 || get_query_var('surerank_prefix') !== 'post-type') return;
        if (!get_option('blog_public') || !class_exists('SureRank\\Inc\\Functions\\Cache')) return;
        $entries = self::cachedPages($threshold);
        // SureRank 1.9.3+ builds a cold cache on demand. Use that same provider
        // so the privacy route is present on the first request as well.
        if (!$entries && class_exists('SureRank\\Inc\\Sitemap\\Providers\\Registry')
            && class_exists('SureRank\\Inc\\Sitemap\\Generation_Mode')
            && \SureRank\Inc\Sitemap\Generation_Mode::allows_inline_build()) {
            $provider = \SureRank\Inc\Sitemap\Providers\Registry::get_instance()->for_request('page', 'post-type');
            $provider?->ensure_page_built('page', 'post-type', 1);
            $entries = self::cachedPages($threshold);
        }
        if (!$entries) return; // Let the provider handle a missing/rebuilding cache.
        // Recent SureRank versions publish the blog in their archive sitemap.
        $paths = class_exists('SureRank\\Inc\\BatchProcess\\Sync_Archives') ? [SitePages::PRIVACY] : ['/blog/', SitePages::PRIVACY];
        $paths[] = SitePages::REFERENCES;
        $paths[] = '/is-ortaklarimiz/';
        foreach ($paths as $path) {
            $url = home_url($path);
            $template = $path === '/is-ortaklarimiz/' ? 'partners.php' : ($path === SitePages::REFERENCES ? 'references.php' : 'privacy.php');
            if (!in_array($url, array_column($entries, 'link'), true)) $entries[] = ['link' => $url, 'updated' => gmdate('c', filemtime(ROOT . '/templates/' . $template))];
        }
        $xml = \SureRank\Inc\Sitemap\Utils::sitemap_main($entries);
        if (method_exists('SureRank\\Inc\\Sitemap\\Utils', 'strip_leading_noise')) $xml = \SureRank\Inc\Sitemap\Utils::strip_leading_noise($xml);
        \SureRank\Inc\Sitemap\Utils::output_headers();
        echo $xml;
        exit;
    }

    private static function cachedPages(int $threshold): array
    {
        $chunkSize = max(1, (int) apply_filters('surerank_sitemap_json_chunk_size', 20));
        $entries = [];
        for ($chunk = 1; $chunk <= (int) ceil($threshold / $chunkSize); $chunk++) {
            $data = \SureRank\Inc\Functions\Cache::get_file('sitemap/post-type-page-chunk-' . $chunk . '.json');
            if ($data) $entries = array_merge($entries, json_decode($data, true) ?: []);
        }
        return $entries;
    }
}

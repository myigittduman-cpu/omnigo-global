<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

/** Public marketing content; independent of store credentials and live connections. */
final class MarketplaceSection
{
    public static function active(): bool
    {
        return !is_admin() && !is_feed() && !is_preview()
            && is_front_page();
    }

    public static function boot(): void
    {
        add_action('wp_enqueue_scripts', static function (): void {
            if (!self::active() && !PartnersPage::active()) return;
            wp_enqueue_style('omnigo-marketplaces', plugins_url('assets/marketplaces.css', ROOT . '/omnigo-blog.php'), ['omnigo-portfolio'], VERSION . '.' . filemtime(ROOT . '/assets/marketplaces.css'));
        });
        add_action('template_redirect', static function (): void {
            if (!self::active()) return;
            ob_start();
            require ROOT . '/templates/marketplaces.php';
            $section = (string) ob_get_clean();
            ob_start(static fn(string $html): string => self::insert($html, $section));
        }, 21);
    }

    public static function insert(string $html, string $section): string
    {
        if (!str_contains($html, 'class="og-site"') || str_contains($html, 'id="pazaryerleri"')) return $html;
        // Keep the existing header, editorial content and footer unchanged.
        return preg_replace('~</main>(\s*<footer class="og-footer-v2")~u', $section . '</main>$1', $html, 1) ?? $html;
    }
}

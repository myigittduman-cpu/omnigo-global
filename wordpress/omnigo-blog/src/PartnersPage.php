<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

/** Public partner directory; does not alter existing WordPress page content. */
final class PartnersPage
{
    public const PATH = '/is-ortaklarimiz/';

    public static function active(): bool
    {
        return !is_admin() && !is_feed() && !is_preview() && SitePages::path() === self::PATH;
    }

    public static function details(): ?array
    {
        return self::active() ? ['title' => 'İş Ortaklarımız ve Pazaryerleri | Omnigo Global',
            'description' => 'Çalıştığımız pazaryerlerini ve mağazalar için sunduğumuz yönetim, operasyon ve entegrasyon hizmetlerinin kapsamını keşfedin.',
            'url' => home_url(self::PATH)] : null;
    }

    public static function boot(): void
    {
        add_action('wp', static function (): void {
            if (!self::active()) return;
            global $wp_query;
            $wp_query->is_404 = false;
            status_header(200);
        }, 6);
        add_filter('template_include', static fn(string $template): string => self::active() ? ROOT . '/templates/partners.php' : $template, 102);
        add_action('template_redirect', static function (): void {
            if (self::active()) ob_start([SiteContent::class, 'update']);
        }, 20);
    }
}

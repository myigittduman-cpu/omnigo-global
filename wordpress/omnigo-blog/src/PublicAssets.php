<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class PublicAssets
{
    public static function trimFontMarkup(string $html): string
    {
        if (!str_contains($html, 'class="og-site') && !str_contains($html, 'ogb-shell')) return $html;
        // Elementor can print these fallback fonts after wp_enqueue_scripts.
        return preg_replace('~<link\b[^>]*\bid=(["\x27])(?:astra-google-fonts|elementor-gf-roboto|elementor-gf-robotoslab)-css\1[^>]*>~iu', '', $html) ?? $html;
    }

    public static function boot(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'trimBookingAssets'], 100);
    }

    public static function trimBookingAssets(): void
    {
        if (is_admin() || wp_doing_ajax() || is_preview() || is_customize_preview()
            || (defined('REST_REQUEST') && REST_REQUEST) || isset($_GET['elementor-preview'])) return;
        // Restrict this optimization to the public pages maintained by this plugin.
        if (!SitePages::details() && !Frontend::active() && !PortalPage::active()) return;
        // Corporate templates use Segoe UI / Arial; builder fallback fonts are unused.
        foreach (['astra-google-fonts', 'elementor-gf-roboto', 'elementor-gf-robotoslab'] as $handle) wp_dequeue_style($handle);
        $post = get_queried_object();
        $content = (string) ($post->post_content ?? '');
        $builder = isset($post->ID) ? (string) get_post_meta((int) $post->ID, '_elementor_data', true) : '';
        // Keep booking shortcodes, blocks and Elementor widgets functional if added later.
        if (stripos($content . $builder, 'latepoint') !== false
            || apply_filters('omnigo_keep_booking_assets', false)) return;
        wp_dequeue_style('latepoint-main-front');
        wp_dequeue_script('latepoint-main-front');
        wp_dequeue_script('latepoint-vendor-front');
    }
}

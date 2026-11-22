<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class Seo
{
    public static function boot(): void
    {
        add_action('wp', [self::class, 'prepare'], 99);
        add_filter('pre_get_document_title', [self::class, 'title'], 999);
    }

    public static function prepare(): void
    {
        if (!Frontend::active() && !PortalPage::active() && !SitePages::details() && !is_404()) return;
        // Keep the sitemap provider; own metadata for the public corporate pages.
        if (class_exists('SureRank\\Inc\\Frontend\\Meta_Data')) {
            remove_action('wp_head', [\SureRank\Inc\Frontend\Meta_Data::get_instance(), 'print_meta_data'], 2);
        }
        remove_action('wp_head', 'rel_canonical');
        remove_action('wp_head', 'wp_robots');
        add_action('wp_head', [self::class, 'head'], 3);
        if (is_preview() || (is_singular() && get_post_status(get_queried_object_id()) !== 'publish')) nocache_headers();
    }

    public static function title(string $title): string
    {
        if (is_404()) return 'Sayfa Bulunamadı | Omnigo Global';
        if ($page = SitePages::details()) return $page['title'];
        if (PortalPage::active()) return 'Müşteri Portalı | Omnigo Global';
        if (!Frontend::active()) return $title;
        if (is_singular()) return PostMetadata::get(get_queried_object_id(), 'seo_title') ?: get_the_title() . ' | Omnigo Blog';
        $page = max(1, (int) get_query_var('paged'));
        return 'E-Ticaret ve Pazaryeri Yönetimi Blogu' . ($page > 1 ? ' – Sayfa ' . $page : '') . ' | Omnigo Global';
    }

    public static function data(): array
    {
        if (is_404() || ($page = SitePages::details())) {
            $page = $page ?? ['title' => self::title(''), 'description' => 'Aradığınız sayfa bulunamadı. Omnigo Global ana sayfasından devam edebilirsiniz.', 'url' => home_url('/')];
            return $page + ['image' => Images::url('omnigo-analiz-masasi', 1440), 'socialTitle' => $page['title'], 'socialDescription' => $page['description']];
        }
        if (PortalPage::active()) {
            $description = 'Panel satışları, analiz ve raporlar, aktif çalışmalar, hakedişler, şehir dışı sevkiyat ve destek taleplerinizi Omnigo müşteri portalından takip edin.';
            return ['title' => self::title(''), 'description' => $description, 'url' => home_url('/musteri-portali/'), 'image' => Images::url('omnigo-analiz-masasi', 1440), 'socialTitle' => self::title(''), 'socialDescription' => $description];
        }
        $id = is_singular() ? get_queried_object_id() : 0;
        $description = 'Pazaryeri yönetimi, e-ticaret sitesi, mobil uygulama ve kurumsal web geliştirme üzerine Omnigo Global rehberleri.';
        $url = get_post_type_archive_link(ContentType::POST_TYPE);
        $image = Images::url('omnigo-analiz-masasi', 1440);
        if ($id) {
            $post = get_post($id);
            $description = PostMetadata::get($id, 'seo_description') ?: wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_excerpt ?: $post->post_content)), 30);
            $url = PostMetadata::get($id, 'canonical_url') ?: get_permalink($id);
            $image = PostMetadata::get($id, 'social_image') ?: (get_the_post_thumbnail_url($id, 'large') ?: '');
        } else {
            // Query manipulation needs a raw URL; escape only when writing HTML.
            $url = get_pagenum_link(max(1, (int) get_query_var('paged')), false);
            $url = remove_query_arg(['arama', 'kategori'], $url);
            if (Frontend::parameter('arama') !== '' || Frontend::parameter('kategori') !== '') {
                $url = get_post_type_archive_link(ContentType::POST_TYPE);
            }
        }
        $title = self::title('');
        return ['title' => $title, 'description' => $description, 'url' => $url, 'image' => $image,
            'socialTitle' => $id ? (PostMetadata::get($id, 'social_title') ?: $title) : $title,
            'socialDescription' => $id ? (PostMetadata::get($id, 'social_description') ?: $description) : $description];
    }

    public static function head(): void
    {
        // Corporate pages replace SureRank's metadata, but must retain its
        // public Search Console ownership tag.
        $verification = get_option('surerank_gsc_verification_token', '');
        if (is_string($verification) && $verification !== '') {
            echo '<meta name="google-site-verification" content="' . esc_attr($verification) . '">' . "\n";
        }
        $data = self::data();
        $private = is_404() || !get_option('blog_public') || is_preview()
            || (is_singular() && (get_post_status(get_queried_object_id()) !== 'publish' || post_password_required()));
        $filtered = Frontend::active() && (Frontend::parameter('arama') !== '' || Frontend::parameter('kategori') !== '');
        $noindex = $private || $filtered;
        $robots = $private ? 'noindex, nofollow' : ($filtered ? 'noindex, follow' : 'index, follow, max-image-preview:large');
        echo '<meta name="robots" content="' . $robots . '">' . "\n";
        echo '<meta name="description" content="' . esc_attr($data['description']) . '">' . "\n";
        if (!is_404()) echo '<link rel="canonical" href="' . esc_url($data['url']) . '">' . "\n";
        $tags = ['og:type' => is_singular(ContentType::POST_TYPE) ? 'article' : 'website', 'og:locale' => 'tr_TR',
            'og:site_name' => 'Omnigo Global', 'og:title' => $data['socialTitle'],
            'og:description' => $data['socialDescription'], 'og:url' => $data['url'], 'og:image' => $data['image']];
        foreach ($tags as $key => $value) if ($value !== '') echo '<meta property="' . esc_attr($key) . '" content="' . esc_attr($value) . '">' . "\n";
        foreach (['card' => $data['image'] ? 'summary_large_image' : 'summary', 'title' => $data['socialTitle'], 'description' => $data['socialDescription'], 'image' => $data['image']] as $key => $value) {
            if ($value !== '') echo '<meta name="twitter:' . esc_attr($key) . '" content="' . esc_attr($value) . '">' . "\n";
        }
        if (!$noindex && class_exists(StructuredData::class)) StructuredData::output($data);
    }
}

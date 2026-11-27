<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class Frontend
{
    public static function boot(): void
    {
        add_action('pre_get_posts', [self::class, 'filterQuery']);
        add_filter('template_include', [self::class, 'template'], 99);
        add_action('wp_enqueue_scripts', [self::class, 'assets']);
    }

    public static function active(): bool
    {
        return is_post_type_archive(ContentType::POST_TYPE) || is_singular(ContentType::POST_TYPE);
    }

    public static function parameter(string $key): string
    {
        $value = $_GET[$key] ?? '';
        return is_string($value) ? mb_substr(sanitize_text_field(wp_unslash($value)), 0, 120) : '';
    }

    public static function filterQuery(\WP_Query $query): void
    {
        if (is_admin() || !$query->is_main_query() || !$query->is_post_type_archive(ContentType::POST_TYPE)) return;
        $query->set('posts_per_page', 9);
        $query->set('post_status', 'publish');
        $query->set('has_password', false);
        $query->set('orderby', ['date' => 'DESC', 'ID' => 'DESC']);
        if ($search = self::parameter('arama')) $query->set('s', $search);
        if ($category = self::parameter('kategori')) {
            $query->set('tax_query', [['taxonomy' => ContentType::TAXONOMY, 'field' => 'slug', 'terms' => sanitize_title($category)]]);
        }
    }

    public static function template(string $template): string
    {
        return self::active() ? ROOT . '/templates/' . (is_singular() ? 'single' : 'archive') . '.php' : $template;
    }

    public static function assets(): void
    {
        if (!self::active() && !PortalPage::active() && !SitePages::privacy() && !SitePages::references() && !PartnersPage::active() && !is_404()) return;
        wp_enqueue_style('omnigo-blog', plugins_url('assets/blog.css', ROOT . '/omnigo-blog.php'), [], VERSION);
        wp_enqueue_style('omnigo-site-design', plugins_url('assets/site-design.css', ROOT . '/omnigo-blog.php'), ['omnigo-blog'], VERSION);
    }

    public static function related(int $postId): array
    {
        $terms = wp_get_post_terms($postId, ContentType::TAXONOMY, ['fields' => 'ids']);
        $args = ['post_type' => ContentType::POST_TYPE, 'post_status' => 'publish', 'has_password' => false,
            'posts_per_page' => 3, 'post__not_in' => [$postId], 'orderby' => ['date' => 'DESC', 'ID' => 'DESC'], 'no_found_rows' => true];
        if (!is_wp_error($terms) && $terms) $args['tax_query'] = [['taxonomy' => ContentType::TAXONOMY, 'terms' => $terms]];
        $posts = get_posts($args);
        if (!$posts && isset($args['tax_query'])) { unset($args['tax_query']); $posts = get_posts($args); }
        return $posts;
    }

    public static function card(\WP_Post $post): void
    {
        $url = get_permalink($post);
        echo '<article class="ogb-card"><a class="ogb-card-image" href="' . esc_url($url) . '" tabindex="-1" aria-hidden="true">';
        if (has_post_thumbnail($post)) echo get_the_post_thumbnail($post, 'medium_large', ['loading' => 'lazy', 'alt' => '']);
        else echo '<span class="ogb-image-fallback" aria-hidden="true">OMNIGO <small>GLOBAL</small></span>';
        echo '</a><div class="ogb-card-copy"><div class="ogb-meta">';
        $terms = get_the_terms($post, ContentType::TAXONOMY);
        if ($terms && !is_wp_error($terms)) echo '<span>' . esc_html($terms[0]->name) . '</span><span aria-hidden="true">·</span>';
        echo '<time datetime="' . esc_attr(get_the_date('c', $post)) . '">' . esc_html(get_the_date('j F Y', $post)) . '</time></div>';
        echo '<h2><a href="' . esc_url($url) . '">' . esc_html(get_the_title($post)) . '</a></h2>';
        echo '<p>' . esc_html(wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_excerpt ?: $post->post_content)), 26)) . '</p>';
        echo '<a class="ogb-read" href="' . esc_url($url) . '" aria-label="' . esc_attr(get_the_title($post) . ' yazısını oku') . '">Yazıyı oku <span aria-hidden="true">↗</span></a></div></article>';
    }
}

<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class ContentType
{
    public const POST_TYPE = 'omnigo_article';
    public const TAXONOMY = 'omnigo_topic';

    public static function boot(): void
    {
        add_action('init', [self::class, 'register']);
        add_filter('wp_insert_post_data', [self::class, 'sanitize'], 10, 2);
    }

    public static function register(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Blog yazıları', 'singular_name' => 'Blog yazısı', 'menu_name' => 'Blog',
                'all_items' => 'Tüm yazılar', 'add_new' => 'Yeni yazı ekle', 'add_new_item' => 'Yeni blog yazısı',
                'edit_item' => 'Blog yazısını düzenle', 'new_item' => 'Yeni yazı', 'view_item' => 'Yazıyı görüntüle',
                'search_items' => 'Yazılarda ara', 'not_found' => 'Yazı bulunamadı.',
                'not_found_in_trash' => 'Çöp kutusunda yazı yok.', 'featured_image' => 'Kapak görseli',
                'set_featured_image' => 'Kapak görseli seç', 'remove_featured_image' => 'Kapak görselini kaldır',
                'use_featured_image' => 'Kapak görseli olarak kullan', 'archives' => 'Blog',
            ],
            'public' => true, 'show_in_rest' => true, 'rest_base' => 'omnigo-blog',
            'has_archive' => 'blog', 'rewrite' => ['slug' => 'blog', 'with_front' => false],
            'menu_icon' => 'dashicons-welcome-write-blog', 'menu_position' => 6,
            'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'revisions'],
            'capability_type' => 'post', 'map_meta_cap' => true,
        ]);
        register_taxonomy(self::TAXONOMY, self::POST_TYPE, [
            'labels' => ['name' => 'Blog kategorileri', 'singular_name' => 'Kategori',
                'add_new_item' => 'Yeni kategori ekle', 'edit_item' => 'Kategoriyi düzenle',
                'search_items' => 'Kategorilerde ara', 'all_items' => 'Tüm kategoriler',
                'parent_item' => 'Üst kategori', 'new_item_name' => 'Yeni kategori adı'],
            'public' => false, 'show_ui' => true, 'show_in_rest' => true, 'show_admin_column' => true,
            'hierarchical' => true, 'rewrite' => false, 'query_var' => false,
        ]);
    }

    public static function sanitize(array $data, array $post): array
    {
        if (($data['post_type'] ?? '') === self::POST_TYPE) {
            foreach (['post_content', 'post_excerpt'] as $key) {
                if (isset($data[$key])) $data[$key] = wp_slash(wp_kses_post(wp_unslash($data[$key])));
            }
            $data['post_title'] = wp_slash(sanitize_text_field(wp_unslash($data['post_title'] ?? '')));
        }
        return $data;
    }

    public static function activate(): void
    {
        self::register();
        // Uses existing WordPress posts, terms and metadata tables; no schema migration.
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        unregister_post_type(self::POST_TYPE);
        unregister_taxonomy(self::TAXONOMY);
        flush_rewrite_rules(false);
    }
}

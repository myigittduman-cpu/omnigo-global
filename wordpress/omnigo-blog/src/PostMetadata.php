<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

final class PostMetadata
{
    public const FIELDS = [
        'seo_title' => ['SEO başlığı', 120, 'text'],
        'seo_description' => ['Meta açıklaması', 320, 'textarea'],
        'canonical_url' => ['Canonical URL', 2048, 'url'],
        'social_title' => ['Sosyal paylaşım başlığı', 120, 'text'],
        'social_description' => ['Sosyal paylaşım açıklaması', 320, 'textarea'],
        'social_image' => ['Sosyal paylaşım görseli URL’si', 2048, 'url'],
    ];

    public static function boot(): void
    {
        add_action('add_meta_boxes_' . ContentType::POST_TYPE, [self::class, 'addBox']);
        add_action('save_post_' . ContentType::POST_TYPE, [self::class, 'save']);
    }

    public static function addBox(): void
    {
        add_meta_box('omnigo-blog-seo', 'Arama motorları ve sosyal paylaşım', [self::class, 'render'], ContentType::POST_TYPE);
    }

    public static function render(\WP_Post $post): void
    {
        wp_nonce_field('omnigo_blog_seo', 'omnigo_blog_nonce');
        echo '<p>Boş bıraktığınız alanlar yazı başlığı, özeti, bağlantısı ve kapak görselinden otomatik hazırlanır. Canonical URL’yi yalnızca farklı bir asıl kaynak gösterecekseniz değiştirin.</p>';
        foreach (self::FIELDS as $key => [$label, $limit, $type]) {
            $value = self::get($post->ID, $key);
            echo '<p><label for="og-' . esc_attr($key) . '"><strong>' . esc_html($label) . '</strong></label><br>';
            if ($type === 'textarea') {
                echo '<textarea rows="3" class="widefat" id="og-' . esc_attr($key) . '" name="omnigo_blog[' . esc_attr($key) . ']" maxlength="' . $limit . '">' . esc_textarea($value) . '</textarea>';
            } else {
                echo '<input class="widefat" type="' . $type . '" id="og-' . esc_attr($key) . '" name="omnigo_blog[' . esc_attr($key) . ']" maxlength="' . $limit . '" value="' . esc_attr($value) . '">';
            }
            echo '</p>';
        }
    }

    public static function clean(string $key, mixed $value): string
    {
        if (!isset(self::FIELDS[$key]) || !is_string($value)) return '';
        [, $limit, $type] = self::FIELDS[$key];
        $value = mb_substr(trim($value), 0, $limit);
        return $type === 'url' ? esc_url_raw($value, ['https', 'http']) : sanitize_text_field($value);
    }

    public static function save(int $postId): void
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId) || !current_user_can('edit_post', $postId)) return;
        $nonce = $_POST['omnigo_blog_nonce'] ?? '';
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'omnigo_blog_seo')) return;
        $input = $_POST['omnigo_blog'] ?? null;
        if (!is_array($input)) return;
        foreach (self::FIELDS as $key => $field) {
            if (array_key_exists($key, $input)) update_post_meta($postId, '_omnigo_' . $key, self::clean($key, wp_unslash($input[$key])));
        }
    }

    public static function get(int $postId, string $key): string
    {
        return self::clean($key, get_post_meta($postId, '_omnigo_' . $key, true));
    }
}

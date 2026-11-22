<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

/** One-time, idempotent publication of the approved public service guides. */
final class EditorialContent
{
    private const KEY = 'omnigo_editorial_20261022';

    public static function boot(): void
    {
        add_action('init', [self::class, 'publish'], 30);
    }

    public static function publish(): void
    {
        if (get_option(self::KEY)) return;
        $lock = self::KEY . '_lock';
        $started = (int) get_option($lock);
        if ($started && $started < time() - 300) delete_option($lock);
        if (!add_option($lock, time(), '', false)) return;
        try {
            $articles = json_decode((string) file_get_contents(ROOT . '/content/service-guides.json'), true, 512, JSON_THROW_ON_ERROR);
            $published = [];
            foreach ($articles as $article) {
                $existing = get_page_by_path($article['slug'], OBJECT, ContentType::POST_TYPE);
                if ($existing && $existing->post_status === 'publish') { $published[] = (int) $existing->ID; continue; }
                if ($existing && get_post_meta($existing->ID, '_omnigo_editorial_seed', true) !== self::KEY) throw new \RuntimeException('Existing editorial slug is reserved.');
                $id = $existing ? (int) $existing->ID : wp_insert_post([
                    'post_type' => ContentType::POST_TYPE, 'post_status' => 'draft',
                    'post_title' => $article['title'], 'post_name' => $article['slug'],
                    'post_excerpt' => $article['excerpt'], 'post_content' => $article['content'],
                    'meta_input' => ['_omnigo_editorial_seed' => self::KEY],
                ], true);
                if (is_wp_error($id)) throw new \RuntimeException($id->get_error_message());
                $term = term_exists('Web ve Mobil Çözümler', ContentType::TAXONOMY);
                if (!$term) $term = wp_insert_term('Web ve Mobil Çözümler', ContentType::TAXONOMY, ['slug' => 'web-ve-mobil-cozumler']);
                if (is_wp_error($term)) throw new \RuntimeException($term->get_error_message());
                wp_set_object_terms($id, [(int) (is_array($term) ? $term['term_id'] : $term)], ContentType::TAXONOMY);
                update_post_meta($id, '_omnigo_seo_title', $article['seoTitle']);
                update_post_meta($id, '_omnigo_seo_description', $article['excerpt']);
                $image = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 's' => $article['coverSearch'], 'fields' => 'ids']);
                if ($image) set_post_thumbnail($id, (int) $image[0]);
                $result = wp_update_post(['ID' => $id, 'post_status' => 'publish'], true);
                if (is_wp_error($result)) throw new \RuntimeException($result->get_error_message());
                $published[] = (int) $id;
            }
            update_option(self::KEY, $published, false);
        } catch (\Throwable $error) {
            error_log('Omnigo editorial publication: ' . $error->getMessage());
        } finally {
            delete_option($lock);
        }
    }
}

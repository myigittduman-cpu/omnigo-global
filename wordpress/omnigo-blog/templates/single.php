<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;
the_post();
require ROOT . '/templates/header.php';
$postId = get_the_ID();
$terms = get_the_terms($postId, ContentType::TAXONOMY);
?>
<main id="ogb-main" class="ogb-article ogb-wide"><a class="ogb-back" href="<?php echo esc_url(get_post_type_archive_link(ContentType::POST_TYPE)); ?>">← Tüm yazılar</a>
<?php if (get_post_status() !== 'publish' || is_preview()): ?><p class="ogb-preview" role="status">Taslak önizlemesi — bu içerik ziyaretçilere açık değildir.</p><?php endif; ?>
<article><header class="ogb-article-heading"><div class="ogb-meta"><?php if ($terms && !is_wp_error($terms)) foreach ($terms as $term): ?><a href="<?php echo esc_url(add_query_arg('kategori', $term->slug, get_post_type_archive_link(ContentType::POST_TYPE))); ?>"><?php echo esc_html($term->name); ?></a><?php endforeach; ?><time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('j F Y')); ?></time></div><h1><?php echo esc_html(get_the_title()); ?></h1><?php if (has_excerpt()): ?><p class="ogb-lead"><?php echo esc_html(wp_strip_all_tags(get_the_excerpt())); ?></p><?php endif; ?></header>
<?php if (has_post_thumbnail() && !post_password_required()): ?><figure class="ogb-cover"><?php the_post_thumbnail('large', ['loading' => 'eager', 'fetchpriority' => 'high']); ?></figure><?php endif; ?>
<div class="ogb-content"><?php the_content(); ?></div></article>
<?php $related = Frontend::related($postId); if ($related): ?><section class="ogb-related" aria-labelledby="ogb-related-title"><span class="ogb-eyebrow">OKUMAYA DEVAM EDİN</span><h2 id="ogb-related-title">İlgili yazılar</h2><div class="ogb-grid"><?php foreach ($related as $relatedPost) Frontend::card($relatedPost); ?></div></section><?php endif; ?></main>
<?php require ROOT . '/templates/footer.php'; ?>

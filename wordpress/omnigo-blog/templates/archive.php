<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;
require ROOT . '/templates/header.php';
global $wp_query;
$search = Frontend::parameter('arama');
$category = Frontend::parameter('kategori');
$categories = get_terms(['taxonomy' => ContentType::TAXONOMY, 'hide_empty' => true]);
?>
<main id="ogb-main"><div class="ogb-band"><section class="ogb-hero ogb-wide"><span class="ogb-eyebrow">OMNIGO GLOBAL / BLOG</span><h1>Dijital ticarete dair<br><em>bilgi ve deneyim.</em></h1><p>Pazaryeri yönetimi, e-ticaret siteleri, mobil uygulamalar ve kurumsal web projeleri için rehberler.</p></section></div>
<section class="ogb-wide ogb-listing" aria-label="Blog yazıları"><form class="ogb-filters" method="get" action="<?php echo esc_url(get_post_type_archive_link(ContentType::POST_TYPE)); ?>"><label class="ogb-search">Yazılarda ara<input type="search" name="arama" maxlength="120" placeholder="Başlık veya konu ara…" value="<?php echo esc_attr($search); ?>"></label><label>Kategori<select name="kategori"><option value="">Tüm kategoriler</option><?php if (!is_wp_error($categories)) foreach ($categories as $term): ?><option value="<?php echo esc_attr($term->slug); ?>" <?php selected($category, $term->slug); ?>><?php echo esc_html($term->name); ?></option><?php endforeach; ?></select></label><button type="submit" class="ogb-button">Ara ve filtrele</button><?php if ($search || $category): ?><a class="ogb-clear" href="<?php echo esc_url(get_post_type_archive_link(ContentType::POST_TYPE)); ?>">Temizle</a><?php endif; ?></form>
<?php if (have_posts()): ?><div class="ogb-results"><h2><?php echo ($search || $category) ? 'Arama sonuçları' : 'Son yazılar'; ?></h2><span><?php echo esc_html((string) $wp_query->found_posts); ?> yazı</span></div><div class="ogb-grid"><?php while (have_posts()): the_post(); Frontend::card(get_post()); endwhile; ?></div>
<?php $pagination = paginate_links(['total' => $wp_query->max_num_pages, 'current' => max(1, (int) get_query_var('paged')), 'type' => 'list', 'prev_text' => '← Önceki', 'next_text' => 'Sonraki →', 'add_args' => array_filter(['arama' => $search, 'kategori' => $category])]); if ($pagination): ?><nav class="ogb-pagination" aria-label="Yazı sayfaları"><?php echo wp_kses_post($pagination); ?></nav><?php endif; ?>
<?php else: ?><div class="ogb-empty"><span class="ogb-eyebrow"><?php echo ($search || $category) ? 'ARAMA SONUCU' : 'YENİ BİR SAYFA'; ?></span><h2><?php echo ($search || $category) ? 'Bu aramada yazı bulunamadı.' : 'İlk yazılarımız yakında burada.'; ?></h2><p><?php echo ($search || $category) ? 'Farklı bir kelime deneyin veya kategori filtresini temizleyin.' : 'Pazaryeri yönetimi ve e-ticaret üzerine yazılarımızı bu bölümde paylaşacağız.'; ?></p><?php if ($search || $category): ?><a class="ogb-button ogb-outline" href="<?php echo esc_url(get_post_type_archive_link(ContentType::POST_TYPE)); ?>">Tüm yazılara dön</a><?php endif; ?></div><?php endif; ?>
</section></main>
<?php require ROOT . '/templates/footer.php'; ?>

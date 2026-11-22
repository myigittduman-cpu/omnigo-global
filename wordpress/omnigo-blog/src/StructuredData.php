<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;

/** Search metadata built only from the company's existing public content. */
final class StructuredData
{
    public static function graph(array $data): array
    {
        $root = home_url('/');
        $organizationId = $root . '#organization';
        $websiteId = $root . '#website';
        $pageId = $data['url'] . '#webpage';
        $organization = [
            '@type' => 'Organization', '@id' => $organizationId,
            'name' => 'Omnigo Global',
            'legalName' => 'Omnigo Global E-Ticaret Operasyon ve Danışmanlık Limited Şirketi',
            'founder' => [
                ['@type' => 'Person', 'name' => 'Yiğit Duman'],
                ['@type' => 'Person', 'name' => 'Mertkal Tekkaya'],
            ],
            'url' => $root,
            'logo' => ['@type' => 'ImageObject', 'url' => home_url('/wp-content/uploads/2026/09/omnigo-global-logo-tr.png')],
            'email' => 'info@omnigoglobal.com', 'telephone' => '+905475456696',
            'address' => ['@type' => 'PostalAddress',
                'streetAddress' => 'Kızılırmak Mahallesi, Dumlupınar Bulvarı, Next Level No:3/A, Kat:4, D.No:11',
                'addressLocality' => 'Çankaya', 'addressRegion' => 'Ankara',
                'postalCode' => '06520', 'addressCountry' => 'TR'],
            'description' => 'Pazaryeri yönetimi, e-ticaret danışmanlığı, web sitesi ve mobil uygulama geliştirme hizmetleri.',
            'sameAs' => ['https://www.instagram.com/omnigoglobal/',
                'https://x.com/omnigoglobal',
                'https://www.linkedin.com/in/omnigo-global-11b7aa444/'],
        ];
        $pageType = is_page('hakkimizda') ? 'AboutPage' : (is_page('iletisim') ? 'ContactPage' : 'WebPage');
        if (SitePages::references() || is_post_type_archive(ContentType::POST_TYPE)) $pageType = 'CollectionPage';
        $page = ['@type' => $pageType, '@id' => $pageId, 'url' => $data['url'],
            'name' => $data['title'], 'description' => $data['description'], 'inLanguage' => 'tr-TR',
            'isPartOf' => ['@id' => $websiteId], 'about' => ['@id' => $organizationId]];
        if ($data['image'] !== '') $page['primaryImageOfPage'] = ['@type' => 'ImageObject', 'url' => $data['image']];
        $graph = [$organization, ['@type' => 'WebSite', '@id' => $websiteId, 'url' => $root,
            'name' => 'Omnigo Global', 'alternateName' => 'Omnigo', 'inLanguage' => 'tr-TR', 'publisher' => ['@id' => $organizationId]]];

        if (!is_front_page()) {
            $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Ana Sayfa', 'item' => $root]];
            if (is_singular(ContentType::POST_TYPE)) {
                $items[] = ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => get_post_type_archive_link(ContentType::POST_TYPE)];
            }
            $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1,
                'name' => is_singular(ContentType::POST_TYPE) ? get_the_title(get_queried_object_id()) : str_replace(' | Omnigo Global', '', $data['title']),
                'item' => $data['url']];
            $breadcrumbId = $data['url'] . '#breadcrumb';
            $graph[] = ['@type' => 'BreadcrumbList', '@id' => $breadcrumbId, 'itemListElement' => $items];
            $page['breadcrumb'] = ['@id' => $breadcrumbId];
        }

        if (is_page('uzmanlik-alanlarimiz')) {
            $catalogId = $data['url'] . '#services';
            $services = [
                ['yonetim', 'Pazaryeri yönetimi ve e-ticaret danışmanlığı', 'Mağaza kurulumu, ürün içerikleri, operasyon takibi ve performans raporlaması.'],
                ['e-ticaret-sitesi', 'E-ticaret sitesi geliştirme', 'Ürün kataloğu, kategori, sepet ve sipariş akışlarının tasarlanması ve geliştirilmesi.'],
                ['mobil-uygulama', 'Mobil satış uygulaması geliştirme', 'Markanın ürün inceleme, sepet ve sipariş deneyimini mobil cihazlara taşıyan uygulama geliştirme.'],
                ['kurumsal-web', 'Kurumsal web sitesi tasarımı', 'Marka, hizmet ve iletişim sayfalarından oluşan mobil uyumlu kurumsal web sitesi geliştirme.'],
            ];
            $items = [];
            foreach ($services as $position => [$anchor, $name, $description]) {
                $items[] = ['@type' => 'ListItem', 'position' => $position + 1, 'item' => [
                    '@type' => 'Service', '@id' => $data['url'] . '#' . $anchor,
                    'name' => $name, 'serviceType' => $name, 'description' => $description,
                    'url' => $data['url'] . '#' . $anchor, 'provider' => ['@id' => $organizationId]]];
            }
            $graph[] = ['@type' => 'ItemList', '@id' => $catalogId, 'name' => 'Omnigo Global hizmetleri', 'itemListElement' => $items];
            $page['mainEntity'] = ['@id' => $catalogId];
        }

        if (is_singular(ContentType::POST_TYPE)) {
            $id = get_queried_object_id();
            $articleId = $data['url'] . '#article';
            $article = ['@type' => 'BlogPosting', '@id' => $articleId,
                'headline' => get_the_title($id), 'description' => $data['description'],
                'mainEntityOfPage' => ['@id' => $pageId], 'inLanguage' => 'tr-TR',
                'datePublished' => get_the_date('c', $id), 'dateModified' => get_the_modified_date('c', $id),
                'author' => ['@id' => $organizationId], 'publisher' => ['@id' => $organizationId]];
            if ($data['image'] !== '') $article['image'] = [$data['image']];
            $graph[] = $article;
            $page['mainEntity'] = ['@id' => $articleId];
        }
        $graph[] = $page;
        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    public static function output(array $data): void
    {
        echo '<script type="application/ld+json">' . wp_json_encode(self::graph($data),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
}

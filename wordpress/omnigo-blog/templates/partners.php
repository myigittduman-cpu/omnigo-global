<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;
require ROOT . '/templates/header.php';
?>
<main id="ogb-main" class="og-site og-partners-page">
    <section id="pazaryerleri" class="og-marketplaces" aria-labelledby="og-partners-title">
        <header class="og-marketplaces-heading">
            <span class="og-marketplaces-eyebrow">BİRLİKTE BÜYÜYEN TİCARET</span>
            <h1 id="og-partners-title">İş Ortaklarımız</h1>
            <p>Markaların farklı satış kanallarında daha düzenli ve görünür bir çalışma yapısına ulaşması için pazaryeri yönetimi, operasyon ve entegrasyon süreçlerini bir araya getiriyoruz.</p>
        </header>
        <div class="og-partners-highlight"><span class="og-marketplaces-eyebrow">PAZARYERİ İŞ BİRLİKLERİMİZ</span><h2>Farklı pazaryerleri, ortak bir çalışma anlayışı.</h2><p>Trendyol, Hepsiburada, n11, Pazarama, idefix, PTT AVM, Koçtaş, Teknosa ve MediaMarkt kanallarındaki mağazalar için ürün, sipariş ve operasyon süreçlerini birlikte ele alıyoruz. Yönetim ve entegrasyon çalışmalarını, her pazaryerinin sunduğu olanaklara ve markanızın ihtiyaçlarına göre planlıyoruz.</p></div>
        <h2 class="og-partners-grid-title">Çalıştığımız pazaryerleri</h2>
        <ul class="og-marketplaces-grid">
        <?php foreach (['Trendyol'=>'trendyol.webp', 'Hepsiburada'=>'hepsiburada.png', 'n11'=>'n11.jpg', 'Pazarama'=>'pazarama.jpg', 'idefix'=>'idefix.png', 'PTT AVM'=>'pttavm.svg', 'Koçtaş'=>'koctas.svg', 'Teknosa'=>'teknosa.jpg', 'MediaMarkt'=>'mediamarkt.jpg'] as $name=>$file): ?>
            <li><div class="og-marketplaces-logo"><img src="<?php echo esc_url(plugins_url('assets/marketplaces/'.$file, ROOT.'/omnigo-blog.php')); ?>" alt="" loading="lazy" decoding="async" width="200" height="88"></div><h3><?php echo esc_html($name); ?></h3></li>
        <?php endforeach; ?>
        </ul>
        <p class="og-marketplaces-note">Hizmet ve entegrasyon kapsamı, her pazaryerinin sunduğu olanaklara ve mağazanın ihtiyaçlarına göre belirlenir.</p>
        <div class="og-marketplaces-actions"><a class="og-marketplaces-primary" href="<?php echo esc_url(home_url('/gorusme-talebi/')); ?>">Mağazanız için görüşelim</a><a href="<?php echo esc_url(home_url('/uzmanlik-alanlarimiz/')); ?>">Hizmetlerimizi keşfedin <span aria-hidden="true">→</span></a></div>
    </section>
</main>
<?php require ROOT . '/templates/footer.php'; ?>

<?php
namespace Omnigo\Blog;
defined('ABSPATH') || exit;
$marketplaces = [
    'Trendyol'=>'trendyol.webp', 'Hepsiburada'=>'hepsiburada.png', 'n11'=>'n11.jpg', 'Pazarama'=>'pazarama.jpg',
    'idefix'=>'idefix.png', 'PTT AVM'=>'pttavm.svg', 'Koçtaş'=>'koctas.svg', 'Teknosa'=>'teknosa.jpg', 'MediaMarkt'=>'mediamarkt.jpg',
];
?>
<section id="pazaryerleri" class="og-marketplaces" aria-labelledby="og-marketplaces-title">
    <header class="og-marketplaces-heading">
        <span class="og-marketplaces-eyebrow">İŞ ORTAKLARIMIZ</span>
        <h2 id="og-marketplaces-title">Pazaryerlerinde işinizi birlikte büyütelim.</h2>
        <p>Ürün, sipariş ve operasyon süreçlerinizi mağazanıza uygun kapsamda planlıyor; pazaryeri yönetimi ve entegrasyon ihtiyaçlarınız için yanınızda oluyoruz.</p>
    </header>
    <ul class="og-marketplaces-grid">
        <?php foreach ($marketplaces as $name=>$file): ?>
        <li><div class="og-marketplaces-logo"><img src="<?php echo esc_url(plugins_url('assets/marketplaces/'.$file, ROOT.'/omnigo-blog.php')); ?>" alt="" loading="lazy" decoding="async" width="200" height="88"></div><h3><?php echo esc_html($name); ?></h3></li>
        <?php endforeach; ?>
    </ul>
    <div class="og-marketplaces-actions"><a class="og-marketplaces-primary" href="<?php echo esc_url(home_url('/gorusme-talebi/')); ?>">Mağazanız için görüşelim</a><a href="<?php echo esc_url(home_url('/is-ortaklarimiz/')); ?>">İş ortaklarımızı tanıyın <span aria-hidden="true">→</span></a></div>
</section>

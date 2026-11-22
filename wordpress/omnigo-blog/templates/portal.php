<?php namespace Omnigo\Blog; defined('ABSPATH') || exit; require ROOT . '/templates/header.php'; ?>
<main id="ogb-main">
<div class="ogb-band"><section class="ogb-wide ogb-portal-intro">
 <div><span class="ogb-eyebrow">OMNIGO GLOBAL / MÜŞTERİ PORTALI</span>
 <h1>Markanızın<br><em>çalışma alanı.</em></h1>
 <p class="ogb-lead">Siparişleriniz, stoklarınız, sevkiyatlarınız ve hakedişleriniz. Omnigo ile yürüttüğünüz tüm süreci tek bir yerden takip edin.</p>
 <ul class="ogb-portal-benefits"><li>Ortak lacivert ve altın tasarım</li><li>Bilgisayar, tablet ve telefon</li><li>Hesabınıza özel erişim</li></ul>
 <div class="ogb-portal-actions"><a class="ogb-button" href="https://portal.omnigoglobal.com/demo/">Güncel demoyu incele <span aria-hidden="true">↗</span></a><a class="ogb-button ogb-button-secondary" href="https://portal.omnigoglobal.com/">Müşteri girişi <span aria-hidden="true">↗</span></a></div>
 <p class="ogb-caption">Demo için üyelik gerekmez. Tüm kayıtlar tanıtım amaçlı örnektir; gerçek müşteri verisi içermez.</p></div>
 <figure class="ogb-portal-screen"><img src="<?php echo esc_url(plugins_url('assets/portal-overview-20261102.webp', ROOT . '/omnigo-blog.php')); ?>" width="1440" height="900" alt="Örnek verilerle güncel müşteri portalı genel bakış ekranı" fetchpriority="high"><figcaption>Güncel ortak arayüz · Örnek Ev &amp; Yaşam tanıtım hesabı</figcaption></figure>
</section></div>
<section class="ogb-wide ogb-features ogb-portal-features" aria-labelledby="ogb-features-title">
 <span class="ogb-eyebrow">PORTALINIZDA NELER VAR?</span><h2 id="ogb-features-title">Operasyonunuzun tamamını takip edin.</h2>
 <div class="ogb-grid"><?php foreach (PortalPage::features() as [$number, $title, $text]): ?><article class="ogb-feature"><span class="ogb-eyebrow"><?php echo esc_html($number); ?></span><h3><?php echo esc_html($title); ?></h3><p><?php echo esc_html($text); ?></p></article><?php endforeach; ?></div>
 <aside class="ogb-portal-policy"><p><strong>Her markaya kendi çalışma alanı.</strong> Bölümler ve işlem izinleri firmanıza ve kullanıcı hesabınıza göre tanımlanır. Müşteriler kendi firmalarının verilerine erişir. Hakedişler Omnigo tarafından elle kaydedilir; pazaryeri bağlantıları, firmanın kendi erişim bilgileriyle kurulur.</p></aside>
</section>
<section class="ogb-wide ogb-portal-examples" aria-labelledby="ogb-examples-title">
 <span class="ogb-eyebrow">GÜNCEL EKRANLARDAN</span><h2 id="ogb-examples-title">Düzenli tablolar. Açık bilgiler.</h2>
 <p>Masaüstünde geniş çalışma alanı, mobilde tüm sütunları yatay kaydırarak inceleyebileceğiniz tam tablolar. Aynı tasarım, aynı işlem düzeni.</p>
 <div class="ogb-portal-gallery">
 <figure class="ogb-portal-screen"><img src="<?php echo esc_url(plugins_url('assets/portal-shipments-20261102.webp', ROOT . '/omnigo-blog.php')); ?>" width="1440" height="900" loading="lazy" alt="Örnek siparişlerle güncel şehir dışı sevkiyat tablosu"><figcaption><strong>Şehir Dışı Sevkiyat</strong>Ay, pazaryeri ve şehir filtreleriyle örnek sevkiyat kayıtları.</figcaption></figure>
 <figure class="ogb-portal-screen"><img src="<?php echo esc_url(plugins_url('assets/portal-inventory-20261102.webp', ROOT . '/omnigo-blog.php')); ?>" width="1440" height="900" loading="lazy" alt="Örnek ürünlerle pazaryeri ve mağaza stoklarının görünümü"><figcaption><strong>Stok Yönetimi</strong>Stok miktarları, satış ayrıntıları ve kayıtlı ürün hareketleri.</figcaption></figure>
 </div>
</section>
<section class="ogb-wide ogb-help" aria-labelledby="ogb-help-title">
 <div><span class="ogb-eyebrow">MERAK EDİLENLER</span><h2 id="ogb-help-title">Başlamadan önce.</h2></div>
 <div>
 <details><summary>Demoda neleri deneyebilirim?</summary><p>Genel bakış, siparişler, stoklar, sevkiyat, teslimat listeleri, iadeler, analizler, hakedişler, çalışmalar ve destek örnekleri arasında gezebilirsiniz. Filtreler ve ayrıntı ekranları örnek kayıtlarla çalışır. Demo veri kaydetmez; belge yükleme ve mesaj gönderme işlemleri kapalıdır.</p></details>
 <details><summary>Portal hesabıma nasıl giriş yaparım?</summary><p>Hesabınız Omnigo tarafından tanımlanır. E-posta adresiniz veya kullanıcı adınız ve şifrenizle giriş yapabilirsiniz. İlk girişte, istenirse kişisel şifrenizi oluşturursunuz.</p></details>
 <details><summary>Telefonda ve bilgisayarda kullanabilir miyim?</summary><p>Evet. Aynı hesabı desteklenen tarayıcılar ve uygulamalar üzerinden kullanabilirsiniz. Bildirimler için cihazınızın desteklediği kurulum yöntemini ve bildirim iznini tamamlamanız gerekir.</p></details>
 <details><summary>Her kullanıcı aynı işlemleri yapabilir mi?</summary><p>Görülebilen bölümler ve düzenleme, belge yükleme ya da dışa aktarma gibi işlemler hesabınıza verilen yetkilere bağlıdır. Kullanıcı yönetimi ve pazaryeri bağlantı ayarları müşteri hesabına açılmaz.</p></details>
 <details><summary>Şifremi unuttum, ne yapmalıyım?</summary><p>Giriş ekranındaki “Şifremi unuttum” seçeneğini kullanın. Hesabınıza erişemiyorsanız <a href="<?php echo esc_url(home_url('/iletisim/')); ?>">bizimle iletişime geçin</a>.</p></details>
 <details><summary>Henüz Omnigo müşterisi değilim.</summary><p>Önce demoyu inceleyin. Markanızın ihtiyaçlarını birlikte değerlendirmek için <a href="<?php echo esc_url(home_url('/gorusme-talebi/')); ?>">görüşme talebi oluşturabilirsiniz</a>.</p></details>
 </div>
</section>
</main>
<?php require ROOT . '/templates/footer.php'; ?>

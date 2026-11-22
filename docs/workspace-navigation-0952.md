# Ortak üst menü — 0.9.52

Yönetici ve müşteri çalışma alanları aynı, role göre sınırlanan üst menü bileşenini kullanır. Müşteride yalnız Panel satışları, Analiz ve raporlar, Aktif çalışmalar, Aktif hakedişler, Şehir Dışı Sevkiyat ve Destek taleplerim görünür. Yönetici bölümleri ve menü sırası düzenleme düğmesi müşteriye üretilmez. Yönetici altı ana bölüm, Diğer ve Düzenle erişimini korur.

Menü ortak bir çerçeve içinde, dengeli aralıklar, simge alanları ve belirgin aktif bölüm vurgusuyla düzenlendi. Orta genişlikte iki satıra geçer; 760 piksel ve altında mevcut mobil alt menü kullanılır. Sevkiyat tabloları iki rolde de tam içerik genişliğini kullanır. Kısa yatay ekranlarda sabit başlık içerik alanını daraltmaz.

Gezinme mevcut uygulama olaylarını kullanır. Sunucu yetkilendirmesi, şirket izolasyonu, kaydedilmemiş sevkiyat değişikliklerinin kontrolü, bildirimler ve veri akışları değiştirilmez. Eski admin-navigation dosyaları önbellekteki önceki sürümler ve geri dönüş için korunur.

## Doğrulama

- JavaScript sözdizimi; yeni rol sınırı/HTML kaçış testleri, mevcut bildirim ve sipariş ayrıntısı testleri: 9 test başarılı.
- PHP portal erişim testi: oturum, rol ve şirket izolasyonu grubu başarılı.
- Yerel örnek uygulamada müşteri altı bölüm, mobil Diğer menüsü; yönetici Diğer, bölüm geçişi ve menü sırası kaydetme/varsayılana dönüş kontrol edildi.
- 390, 800, 1280, 1440 ve 1920 piksel ekranlarda yerleşim kontrol edildi. Sayfa yatay taşmıyor; geniş sevkiyat tablosu kendi alanında kaydırılabiliyor.
- Yerel PHP müşteri sevkiyat örneğinde 1920 piksel genişlikte main genişliği 1905 piksel; sol menü ve yönetici düzenleme düğmesi yok.
- Görsel örnek ve responsive ölçümler verification/workspace-navigation-0952 altında tutulur. Örnek görsel gerçek müşteri verisi içermez.

## Yayın ve geri dönüş

Yalnız public/workspace-navigation.js, public/workspace-navigation.css, public/app.js ve public/version.json yayımlanır. Önceki app.js ve sürüm dosyasının SHA-256 değerleri 0951 yayın kaydıyla karşılaştırılır; uyuşmazlıkta işlem durur. Yeni bağımlılıklar önce, uygulama ve sürüm dosyası sonra yüklenir ve içerikleri tekrar okunarak doğrulanır.

Önceki dosyalar sunucuda storage/releases/0952-before.json ve yerelde verification/workspace-navigation-0952/before.json içinde saklanır. Geri dönüş için yedekteki app.js geri alınır ve daha yeni bir sürüm numarası yayımlanır. Yeni kullanılmayan modüller kalabilir. Veritabanı, API bağlantıları ve cron görevleri değiştirilmez; PTTAVM kapalı kalır. Diğer çalışma dosyaları yayına dahil edilmez.

Yayın sonucu deployment.json, iki portalın HTTPS kaynak doğrulaması public-check.json dosyasına kaydedilir.

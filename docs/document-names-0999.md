# Evrak adları ve Windows indirme düzeltmesi — 0.9.99 / 0.2.18

19 Ekim 2026. Teslimat ve sevkiyat PDF/Excel adları seçili firmanın adı, Türkiye saatine göre indirme günü, seçili şehir ve pazaryeriyle oluşturulur. Vizyon Beyaz Eşya'nın adı dosyalarda Vizyona olarak kullanılır; diğer müşteriler kendi adını taşır. Rapor ayı veya sevkiyat tarihi indirme tarihi yerine kullanılmaz.

Örnekler:

- Vizyona 19.10.2026 Ankara Hepsiburada Teslimat Evrakları.pdf
- Vizyona 19.10.2026 Eskişehir n11 Sevkiyat Adresleri.xlsx
- Vizyona 19.10.2026 Ankara Tüm Paneller Teslimat Evrakları.xlsx
- Vizyona 19.10.2026 Tüm Şehirler Tüm Paneller.pdf

Tüm şehirler/paneller adlandırması mevcut toplu seçim kapsamını temsil eder; indirme kapsamı genişletilmedi. Teslimat tarafı halen seçilen şehrin seçilmiş siparişlerini, sevkiyat tarafı halen seçili filtrelerin kayıtlarını indirir. Dosya türünü gizleyen Windows ayarından bağımsız olarak gerçek uzantı .pdf veya .xlsx'tir.

Ortak ad oluşturucu PDF/Excel doğrudan indirme ve paylaşım penceresinden indirmede kullanılır. Paylaşım penceresinden indirme Türkçe adı korur; WhatsApp'a paylaşımda mevcut ASCII uyumluluğu korunur. PDF çizim kodu yedekle birebir karşılaştırılarak korunur; sipariş verisi veya PDF düzeni değiştirilmez.

Windows'ta eski Omnigo- ön eki şartı firma adlı Excel'i engelliyordu. 0.2.18 şirket adlı Excel ve tarihli teslimat/sevkiyat PDF adlarını destekler. 32 MB sınırı, içerik imzası, uzantı, güvenilir kaynak/pencere sınırı, geçersiz karakter ve yol/Windows aygıt adı kontrolleri korunur. Güncelleme iki ayrı mevcut kanal anahtarıyla imzalanır. Yeni adlandırmanın Windows'ta çalışması için uygulama 0.2.18 olmalıdır.

Kaynak yedeği: verification/document-names-0999/source-before.json, local-before.json ve sunucuda storage/releases/document-names-0999-before.json. Bu iş veritabanını değiştirmez; aynı çalışma başındaki doğrulanmış şifreli yedek verification/pending-returns-0998-20261019/backup.json içindedir.

Kontroller: 21 JavaScript testi ve 10 Rust testi başarılı. Türkiye gece yarısı/yıl değişimi, altı şehir, tüm paneller, başka firma adı, geçersiz adlar, dosya içerikleri, seçili firma değişince kaydı durdurma, Türkçe paylaşım indirmesi ve eski dosyanın başarısız kayıtta korunması test edildi. PDF çizim kaynakları yayından önce yedekle eşit olmalıdır. Native kurulum paketleri imza, değiştirilmiş paket reddi ve HTTPS SHA-256 karşılaştırmasından geçirilir. Gerçek Windows uygulamasında son kullanıcı Kaydet penceresi denemesi henüz yapılmadı.

Canlı sonuç: 0.9.99 ve her iki Windows 0.2.18 kanalı yayımlandı. Müşteri portalında 51 Hepsiburada sevkiyat kaydının Excel/PDF dosyaları, yönetici portalında Ankara/Tüm Paneller kapsamındaki 19 teslimat siparişinin Excel/PDF dosyaları doğru Türkçe adlarıyla Downloads klasörüne indirildi. Teslimat testi paylaşım penceresindeki Dosyayı indir yolundan tamamlandı; herhangi bir kişiye paylaşım yapılmadı. Doğrudan teslimat indirmesinde tarayıcının kaydetme seçimi beklendiği için bu deneme sayfa yenilenerek kapatıldı. Dört gerçek indirmenin dosya adı ve içerik doğrulaması verification/document-names-0999/downloads.json içinde, ekran görüntüleri aynı klasörde.

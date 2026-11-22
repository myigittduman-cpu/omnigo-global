# Bekleyen iadeler ve Windows Excel kaydı · 19 Ekim 2026

Web sürümü 0.9.98; iki Windows kanalı 0.2.17. Mobil paket değişmedi.

## İade okuma kapsamı

- Pazarama: mevcut getOrdersForApi tarih/sayfa taramasındaki 7 (iade süreci başlatıldı), 8 (onaylandı), 9 (reddedildi), 10 (iade edildi) satır durumları alınır. Yeni istek veya finans hesabı eklenmez. Talep numarası/tarihi/adedi/tutarı ayrı verilmediğinden sipariş bilgileri bu alanlarmış gibi sunulmaz; kaydın sipariş satırı olduğu belirtilir. İlk tam taramadan önceki liste korunur. Canlı tarama başlangıcı 1 Eylül 2026.
- Koçtaş: resmi Mirakl satıcı RT11 GET /api/returns. Ayrı kalıcı imleç ve kısa süreli çalışma kilidi, mevcut sync_koctas JSON içinde tutulur. Normal kontrol 15 dakika, çok sayfalı aktarımda sayfalar arasında en az 5 dakika; Retry-After önceliklidir. Tarih aralığı mevcut bağlantı başlangıcından itibaren. Sipariş işçisi sonrasında çalışır; iade servisi hatası sipariş işini başarısız yapmaz. Talep kabulü, kapatılması ve ödeme birbirine karıştırılmaz. Tanınmayan durumlar Kontrol gerekenler altında kalır.
- Eski onaylı sipariş iadeleri korunur; talep kaydında temsil edilen sipariş için ikinci toplu iade satırı üretilmez. Talep kimliğiyle aynı veri tekilleştirilir, çelişkili tam tarama yayımlanmaz.
- İade Talepleri yalnız yönetici rolünde. İki uygulama kapalıyken sunucu cron görevleri devam eder. Yeni tablo, yetki veya kalıcı cron eklenmedi. Satıcıya iade onayı, ret veya geri ödeme gönderilmez.

Resmi kaynaklar:

- https://cdn.pazarama.com/asset/entegrasyondokumani/APIEntegrasyonDokumani18.09.2023.pdf (OrderItemStatus tablosu)
- https://developer.mirakl.com/content/product/mmp/rest/seller/openapi3/returns/rt11

## Windows Excel düzeltmesi

Firma markasına geçirilen Excel dosyaları Windows'un eski Omnigo- dosya adı koşuluna takılıyordu. Yeni sürüm güvenli firma adlı .xlsx dosyalarını kabul eder. Dosya boyutu, ZIP imzası, güvenilir portal ve ana pencere koşulları korunur. Yol geçişi, geçersiz karakterler, gizli adlar ve Windows aygıt adları reddedilir. PDF/JPEG ad kuralları ve PDF biçimleri değişmedi.

İki paket mevcut ayrı kanal anahtarlarıyla imzalandı. İmza ve değiştirilmiş paketin reddi doğrulandı; HTTPS üzerinden yüklenen dosyaların SHA-256 değerleri yerel paketlerle aynı. Kullanıcının Windows uygulamasını giriş ekranındaki Güncellemeler ile 0.2.17'ye güncellemesi gerekir. Yerel Windows arayüzünde son kullanıcı kaydetme denemesi henüz yapılmadı; dosya kaydetme işlevinin birim testleri ve tarayıcıdaki iki panel indirmesi doğrulandı.

## Yedek ve test kanıtları

- Şifreli üretim yedeği: verification/pending-returns-0998-20261019/backup.json; 5.479.487 bayt, SHA-256 c30224a72ab88577ec56bd287b0d1d0f220a3561db593b37f12c2e4cff939b01. Geçici yedek ve API kontrol görevleri kaldırıldı; diğer cron görevleri aynı.
- Değişen kaynakların hem yerel hem sunucudaki yedeği source-before.json; desktop-source-before.json yerel masaüstü kaynak yedeği. Masaüstü eski kanal manifestleri verification/desktop-0217/plan.json içinde ve sunucuda storage/releases/desktop-0217-before.json.
- pending-return-sync.php: gerçek Pazarama adaptörüyle durum geçişleri; Koçtaş tam/sayfalı tarama, çift kayıt, 429, bozuk yanıt, boş tam yanıt, eski kayıt koruması, kilit, firma sınırı ve finans kayıtlarının değişmemesi.
- return-requests.php; pazarama-sales.php; koctas.php; fast-sales-integration.php; return-integration.php (n11/idefix/Hepsiburada) geçti.
- JS: returns-view, workbook-exports, report-download, workspace-navigation: 18 test geçti. PHP sözdizimi ve JS sözdizimi kontrol edildi.
- Rust: 9 test geçti (ilk sandbox denemesinde geçici dosya yazma engeli; aynı testler izinli ortamda geçti). İki Windows kurulum paketi üretildi, imzalandı ve doğrulandı.
- Canlı otomatik tarama: Pazarama 19.10.2026 01:30 sonrası, Koçtaş 01:30; ikisi de Son tarama tamamlandı. Bekleyen filtresi iki kaynak için 0; bütün sağlayıcılarda mevcut 20 iade satırı korundu. Test için gerçek sipariş değiştirilmedi.
- Yönetici ve müşteri sevkiyatında aynı kapsamda 51 kayıt; tarayıcı Excel indirmesi iki panelde de başladı ve indirme başarı mesajı görüldü.

Dosya adlarına ilişkin sonraki kullanıcı isteği 0.9.99 / Windows 0.2.18 kapsamında docs/document-names-0999.md dosyasında izlenir. 0.2.17 önceki ara sürümdür.

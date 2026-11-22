# Dakikalık sipariş okuma (0.9.35)

Siparişler sayfası, tamamlanmış finans verisi yanında `recent_orders` tablosundaki yeni siparişleri de gösterir. Tam finans aktarımı aynı siparişi yayımlayınca listede tek kayıt kalır. Kişisel bilgiler bu indekse yazılmaz; şifreli teslimat kayıtlarında tutulur. Finans toplamı ve iade taraması kendi imleciyle devam eder.

Koçtaş güncellenme tarihini; n11, Pazarama ve idefix kısa oluşturulma tarihi penceresini okur. Diğer üç pazaryerinde 10 dakikalık örtüşme, sayfa imleci ve ayrı tarihsel tarama bulunur. Yeni sipariş, teslimat ve sevkiyat kaydı aynı veritabanı işleminde yazılır. Şehir dışı sevkiyat mevcut altı şehirle sınırlıdır. Elle düzenlenen kayıtlar korunur.

Kurulumda önce şifreli yedek alınır ve `RecentOrderSchema::install()` çalıştırılır (standart `bin/migrate.php` içinde de vardır). Ardından dört ayrı dakikalık cron tanımlanır:

```text
* * * * * /usr/local/bin/php /home/omnigogl/portal/bin/orders-worker.php koctas
* * * * * /usr/local/bin/php /home/omnigogl/portal/bin/orders-worker.php n11
* * * * * /usr/local/bin/php /home/omnigogl/portal/bin/orders-worker.php pazarama
* * * * * /usr/local/bin/php /home/omnigogl/portal/bin/orders-worker.php idefix
```

Dört görev doğrulandıktan sonra web kökü dışındaki `storage/order-workers.enabled` dosyası oluşturulur. Bu işaret yokken ana işçi eski sipariş akışını sürdürür, ayrı sipariş işçileri çalışmaz. İşaret varken ana işçi soru, bildirim ve diğer işleri yürütür. İşareti kaldırmak ayrı işçileri durdurur ve siparişleri ana işçiye geri verir.

Her işçinin kendi dosya kilidi, pazaryeri filtresi ve 50 saniyelik bütçesi vardır. Kuyruk lease/token koruması, Retry-After ve artan hata bekleme süreleri geçerlidir. Koçtaş istekleri en az 60 saniye aralıklıdır. Normal durumda hedef 1–2 dakikadır; kaynağın gecikmesi, sayfa sayısı veya erişim sorunu bu süreyi uzatabilir. Eski hata, engel ve geri çekilme durumları etkinleştirme sırasında sıfırlanmaz.

Kontroller: `php/tests/recent-orders.php`, `php/tests/order-transfer-flow.php`, mevcut pazaryeri/teslimat/sevkiyat testleri. Canlı kontrol kayıtları `verification/portal-0935/` altında, kişisel veri içermeyen sayım ve zamanlarla saklanır.

## Finansal özetlerin dakikalık güncellenmesi — 16 Ekim 2026

`OrderAdapterFactory`, n11, Pazarama ve idefix mali okuyucularını `FinancialRefreshBatch` ile çalıştırır. Bir çevrim en fazla sekiz kalıcı sayfayı, devam sayfaları arasında bir saniye bekleyerek ve işçinin kalan süresini gözeterek okur. Son sayfa tamamlanınca durur; eksik tarama önceki doğru toplamın yerini almaz. Siparişler ve iadeler ayrı imleçlerini korur. Koçtaş mevcut güncellenme tarihi imlecini kullanır.

Sağlıklı taramanın 15 dakikalık beklemesi kaldırılmıştır. Yeni sipariş ve mali tarama aynı çevrim başlangıcından 60 saniye sonrasına planlanır; API yanıtının birkaç saniye sürmesi her turda ilave bir dakika kaymaya yol açmaz. `FixedCadenceReadAdapter` yalnız bu hızlı sipariş adaptörlerinde kullanılır; diğer kuyrukların planı değişmez. Eski sağlıklı zamanlayıcı otomatik kısalır, gerçek hata beklemeleri ve `Retry-After` korunur.

Yönetici ve müşteri paneli aynı tamamlanmış mali veriyi okur. Görünür ekranın mevcut 30 saniyelik yenilemesi ile normal çalışma hedefi 1–2 dakikadır. Kaynağın veriyi geç sunması, bağlantı hatası, hız sınırı veya tek çevrime sığmayan büyük geçmiş taraması bu süreyi uzatabilir. Güncellik için yalnız işin tamamlanma saatine değil `watermark` değerine de bakılmalıdır.

Ek kontroller: `php/tests/fast-sales.php`, `php/tests/fast-sales-integration.php` ve gecikmeli kaynak yanıtını içeren `php/tests/recent-orders.php`. Şifreli yedek doğrulaması, kaynak geri dönüş kopyası, test sonuçları ve salt okunur canlı ölçümler `verification/fast-sales-0955/` altında tutulur. Bu düzenleme veritabanı şemasını, kimlik bilgilerini, arayüzü veya kalıcı cron tanımlarını değiştirmez.

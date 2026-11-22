# Sipariş tutar dökümü ve genel bakış — 0.9.62

17 Ekim 2026

## Davranış

- Yönetici Genel bakış ekranında “Markanızın güncel görünümü” altındaki genel özet kartları ilk sıradadır. Pazaryeri satış özetleri ve sürekli açık günlük grafikler ikinci, “Bu ay pazaryeri satışları” üçüncü sıradadır. Diğer bölümlerin sırası korunur.
- Siparişler tablosunda doğrulanmış idefix indirim bilgileri doğrudan Tutar hücresinde görünür: indirim öncesi, pazaryeri indirimi, satıcı indirimi ve indirim sonrası. Ayrı düğme gerekmez. Ayrıntı penceresi aynı bilgiyi tekrar gösterir.
- Mobil kartta indirim tutarı alanı tam genişlikte yer alır. Masaüstü tablosu mevcut tablo davranışını korur.
- `OrderPricing` yalnız açıklayıcı ürün metaverisi üretir. Brüt, iptal, iade, net, fatura ve sevkiyat tutarlarının hesaplanması değiştirilmez. Şema değişikliği yoktur.
- Eksik, negatif veya matematiksel olarak tutarsız kaynak verilerinden indirim üretilmez. “İndirim yok” yalnız kaynak sıfır indirim bildirdiğinde gösterilir. Diğer pazaryerlerine doğrulanmamış indirim bilgisi eklenmez.
- Müşteri menüsü ve yetkileri korunur; Siparişler yöneticiye özel kalır. Müşteri satış grafikleri ve tutarları kontrol kapsamındadır.

## Önceki 0.9.61 düzeltmesi ve canlı kontrol

Hepsiburada hazır paket sorgusu varsayılan 24 saatlik pencere yerine açık `timespan=1680` kullanır. Diğer sipariş akışları, kuyruk ve yeniden deneme davranışı korunur. Normal kuyruk, 16 Ekim tarihli 34.779,06 TL tutarındaki eksik hazır siparişi kendisi aktardı.

Bağımsız API kontrolünde erişilen 119 sipariş / 129 ürün satırı doğrulandı. Teslimat evraklarında 34 aktif kayıt, otomatik şehir dışı sevkiyatta beklenen 25 kayıt eşleşti; 22 kargolanmış kayıt mavi durumdaydı. Mükerrer sipariş, teslimat, otomatik sevkiyat kaynağı veya manuel/otomatik çakışma bulunmadı. Önceden elle girilen sevkiyatlar korunur.

Bu sonuç tüm tarihsel arşivin eksiksiz olduğu anlamına gelmez. Operasyon kaynaklarında 73 Eylül ve 46 Ekim siparişi vardır; satış grafiklerinin doğrulanmış kapsamı 1 Ekim’den başlar. Eylül finans geçmişi henüz tamamlanmamıştır. Eski manuel sevkiyatları çoğaltabilecek toplu yeniden aktarım yapılmadı.

## Doğrulama ve geri dönüş

- Şifreli canlı veri yedeği: `verification/hepsiburada-package-fix-20261017/data-backup.json`.
- Önceki kaynaklar: `data/source-backups/order-pricing-before-20261017` ve özel sunucu dizinindeki `storage/releases/order-pricing-before20261017.json`.
- PHP idefix sipariş/iadeler ve JavaScript tutar, sipariş ayrıntıları, liste, satış kartları ve aylık satış testleri: `verification/order-pricing-20261017/tests.json`.
- Hepsiburada salt okunur tam akış raporu: `verification/hepsiburada-package-fix-20261017/flow/audit.json`; geçici dosya/görev temizliği: `final-checks.json`.
- Canlı idefix fiyat metaverisi ve yönetici/müşteri tutar karşılaştırması: `verification/order-pricing-20261017/audit.json`; başarılı sonuç: `verified.json`.
- Yalnız izin listesindeki sekiz uygulama dosyası yayımlandı. Sürüm dosyası son sırada yüklendi; kaynakların SHA-256 değerleri doğrulandı.

## Tutar farklarının yorumu

İdefix'in 17 Ekim ekranındaki 100.831 TL, iki siparişin indirim öncesi toplamıdır. Kaynaktaki 10.083,10 TL pazaryeri indirimi sonrası uygulamadaki toplam 90.747,90 TL'dir. İptal ve iadeler mevcut satış kuralıyla ayrıca düşülür.

Pazaryeri ekranlarıyla karşılaştırmada tarih aralığı da aynı olmalıdır. Örneğin n11'de 10 Ekim tarihli 53.586 TL sipariş, gösterilen haftalık tutar farkına tam olarak karşılık gelir. Hepsiburada ekranındaki 7/30 günlük rakamlar da eksik sipariş düzeltmesinden sonra önceki günle biten aralık ve iptal düşümüyle eşleşir; uygulamanın aralığı bugünü içerir. Bu tespitler, pazaryerlerinin net satış veya iade tanımının uygulamayla aynı olduğunu varsaymaz.

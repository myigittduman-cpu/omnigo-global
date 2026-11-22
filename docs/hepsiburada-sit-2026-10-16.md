# Hepsiburada SIT kontrolü — 16 Ekim 2026

Bu çalışma yalnız Hepsiburada'nın SIT servislerinde yürütüldü. Gerçek mağaza anahtarı ve Entegra bağlantısı kullanılmadı. Üretim veritabanı açılmadı; Hepsiburada canlı panelde gizli kalır.

## Doğrulananlar

- Basic Authentication ve User-Agent ile test sipariş, paket, iptal, kargo, teslim ve ürün listelerine HTTP 200 erişim.
- İlk doküman örneğindeki ürün test mağazasında bulunmadığından `GetListingNotFoundError` alındı. Mağazadaki satışa açık SIT ürünü okunarak yeni bir sentetik sipariş oluşturuldu (201).
- İki adet ürün içeren sipariş açık sipariş listesinde görüldü. Satır toplamının adetle ikinci kez çarpılmaması ve satıcı ürün kodunun korunması yerel testlerle doğrulandı.
- Yalnız bu test siparişi paketlendi (201), paket listesinde görüldü ve açık sipariş listesinden çıktı.
- Yalnız bu paket açıldı (200), ardından test siparişi iptal edildi (200).
- Son kontrolde açık ve paketli listeler boş, iptal listesinde yalnız test siparişi vardı. Test kaydı SIT iptal geçmişinde bırakıldı; kalıcı silme yapılmadı.
- İptal/kargo/teslim servislerinde `totalCount: 0, items: null` boş sonuç biçimi gözlendi. Test aracı yalnız toplam sıfırken bunu boş liste olarak ele alır.
- Sabit SIT adresleri, yönlendirme takip etmeme, TLS doğrulaması, 429 bekleme süresi, yazma işlemlerini otomatik tekrarlamama ve başka siparişleri değiştirmeme kontrolleri geçti.

## Henüz doğrulanmayanlar

- Kargoya verildi ve teslim edildi durumlarının dolu kayıtlarla uçtan uca geçişi. Bu listelere erişim başarılı; örnekler boştu.
- Canlı Merchant ID / servis anahtarı / `vizyona_dev` yetkisi ve Hepsiburada'nın zorunlu kabul testleri için destek yanıtı bekleniyor.
- Hepsiburada adaptörü uygulamada şu anda erişim ve sınırlı önizleme yapıyor. Siparişler, teslimat evrakları ve sevkiyat tablolarına tam üretim aktarımı bu testin tamamlandığı iddiasıyla açılmamalıdır.
- Yerelde bulunan `merchantSKU` alan adı düzeltmesi test edildi, canlıya yayımlanmadı.

Test aracı: `tools/hepsiburada-sit/`. Kimlik bilgileri gizli terminal girdisinden yalnız belleğe alınır; çıktı dosyalarında servis anahtarı bulunmaz. Sentetik test kanıtları Git dışındaki `verification/hepsiburada-sit-2026-10-16/` dizinindedir. Kontrol sırasında cron veya sunucu kontrol dosyası oluşturulmadı.

Resmî kaynaklar:
- [Sipariş test süreci](https://developers.hepsiburada.com/tr/companies/hepsiburada?category=siparis-yonetimi&product=siparis-olusturma-entegrasyonu&version=v1.0&guide=siparis-entegrasyonu-test-sureci-adimlari&view=guide)
- [Entegratöre servis anahtarı](https://developers.hepsiburada.com/tr/companies/hepsiburada?category=baslangic&product=api-authentication&version=v1.0&guide=entegratore-servis-anahtari-eklemegoruntuleme&view=guide)

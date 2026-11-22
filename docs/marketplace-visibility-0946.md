# 0.9.46 — Geçici pazaryeri görünürlüğü

PTT AVM, Hepsiburada, Trendyol ve Teknosa, bağlantıları hazırlanırken iki portalda gizlenir. n11, idefix, Pazarama ve Koçtaş görünür kalır.

- Genel bakış, pazaryeri kartları, satış özetleri, hakedişler, rapor seçimleri ve bağlantı kontrolü aynı görünürlük kuralını uygular.
- Sevkiyatın başlangıç seçimi n11 olur. Gizli pazaryerlerine ait eski paylaşım bağlantıları ve HTTP liste/dışa aktarma istekleri açılmaz.
- Teslimat listeleri, şehir sayaçları, geciken/kargodaki siparişler, PDF/Excel/WhatsApp seçimleri ve evrak arşivi süzülür. Filtre sayfalamadan önce uygulanır. Karma evrakın yalnız görünür siparişleri sunulur; şifreli asıl evrak değişmez.
- Bildirim listeleri, sayaçları ve toplu işlemleri gizli kanalları dışarıda bırakır.
- Veritabanında silme, şema değişikliği veya entegrasyon ayarı değişikliği yapılmaz. Aktarım işçileri ve tam pazaryeri kataloğu korunur.

Yeniden açmak için `php/src/MarketplaceVisibility.php` ve `public/marketplace-visibility.js` içindeki ilgili geçici gizleme anahtarı kaldırılır; katalog veya geçmiş veriler yeniden oluşturulmaz. İki kuralın tutarlılığı testle denetlenir.

Doğrulama: iki rolün HTTP uçları, eski paylaşım bağlantıları, şehir/sayfa sayıları, indirilecek veri seçimi, karma arşiv, bildirimler ve kaynak kayıtların değişmemesi. Son sürümün 59 regresyon grubu başarılıdır.

13 Ekim 2026 10:42 Türkiye saati canlı kontrolü: her iki rolün kataloğunda yalnız n11, idefix, Pazarama ve Koçtaş var. 27 görünür aktif teslimat, şehir sayaçları ve kuyruklar doğrulandı. Hepsiburada'nın 448 ve PTT AVM'nin 55 sevkiyat kaydı depoda korunuyor. Dört görünür bağlantı da sağlıklı. Kontrol READ ONLY işleminde çalıştırıldı ve ROLLBACK ile kapandı. Geçici kontrol dosyası, sonuç dosyası ve cron kaldırıldı; diğer cron görevleri başlangıç imzasıyla aynı.

Kanıtlar: `verification/visibility-0946/` içindeki regresyon, yayımlama, public kaynak, canlı kontrol ve temizlik raporları. Yayımlama öncesi kaynak kopyası sunucunun özel `storage/releases/0946-before.json` dosyasında tutulur.

Apple Developer ve Google Play kuruluş işlemleri kullanıcı tarafından sürdürülüyor. Mağaza gönderimi ve fiziksel cihazda kapalı uygulama bildirimi doğrulaması tamamlandı sayılmaz.
Firebase konsolu 13 Ekim'deki yeniden yüklemede yine hizmet koşulları kabul durumunu yükleyemedi. Yeni proje veya izin oluşturulmadı. Tarayıcıdaki yönetici portalı giriş ekranında olduğu için oturumlu görsel kontrol yapılamadı; canlı veri kontrolü yukarıdaki salt okunur sunucu işlemiyle tamamlandı.

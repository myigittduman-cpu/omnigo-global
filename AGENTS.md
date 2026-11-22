# Omnigo ortak arayüz kuralları

## Windows arka plan görevleri

- Yeni Omnigo arka plan, yedekleme, bakım ve doğrulama görevleri görünür konsol açmamalıdır. Görev eyleminde doğrudan PowerShell, cmd, node veya python başlatma; `tools/windows/new-hidden-task-action.ps1` içindeki `New-OmnigoHiddenTaskAction` yardımcısını kullan.
- Yalnızca `-WindowStyle Hidden` veya görevde `Hidden` bayrağı yeterli değildir. Yardımcı, `wscript.exe` üzerinden `WScript.Shell.Run(command, 0, True)` kullanır ve alt işlemin çıkış kodunu görev sonucuna taşır. Uzun EncodedCommand değerlerini tek VBScript satırına yazma.
- Başlatıcıyı projenin kalıcı tools dizininde tut; geçici klasöre veya başka sohbetin outputs dizinine bağımlılık ekleme. Görevlerin çalışma klasörünü, zamanlamasını, kullanıcı yetkilerini, hata kayıtlarını ve tekrar deneme davranışını koru. Alt süreçleri de gizli oluştur (`windowsHide: true` / `CreateNoWindow`).
- Görev oluşturma/düzeltme testinde komutun hata kodunun korunduğunu ve görünür pencere oluşmadığını doğrula. Temizlik gibi veri değiştiren işleri yalnız test için yeniden çalıştırma. Kullanıcı etkileşimi gerektiren işlemlere ve Windows sistem görevlerine bu kuralı uygulama.

## Arayüz ve uygulama kuralları

- Onaylı görünüm lacivert ve altın renkli Dengeli tasarımdır. Kesin ölçüler `docs/design-system.json` dosyasında tutulur.
- Mevcut ve yeni yönetici/müşteri hesapları aynı ortak bileşenleri kullanır. Müşteri adı ve işletme verileri hesap bağlamından gelir; Vizyona tasarım şablonunun veri kaynağı değildir.
- Masaüstü tablolarının onaylı düzenini koru. Mobilde gerçek, tam genişlikte tablolar ve tablo içinde yatay kaydırma kullan; tabloyu karta çevirme veya ekran genişliğine sıkıştırma.
- Mobilde tablo/mesaj gövdesi 14 px, tablo başlığı 13 px, yardımcı açıklama/tarih 12 px, yazı girişleri 16 px olur. Masaüstünde Siparişler tablosunun mevcut 13 px ölçüsü korunur.
- Menü, saat ve şirket unvanını aynı çalışma alanında yeniden oluşturma. Altın renkli şirket unvanı üst alanda sabit, bağlantısız ve hareket azaltma tercihine duyarlı olmalıdır.
- Pencere ve bildirim açılış/kapanışında kayma ve ölçekleme kullanma. Kullanıcının 2 Kasım 2026 tarihli talebiyle sabit ölçülü pencerelerde yalnız 140 ms yumuşak görünme/kapanma ve gerçek kayıt sonrası kısa onay işareti kullanılır; hareket azaltma tercihi korunur. Mevcut düğme, renk, ikon ve fare üstü tasarımlarını koru.
- Yeni sayfa ve pencereler de aynı ortak geçiş kurallarını kullanır: ilk görünür karede son konum ve ölçü hazır olmalı, asenkron veri aynı ayrılmış alana yerleşmeli, kapanan pencere ölçüsü sıfırlanmadan gizlenmeli ve arka sayfanın sekmesi/kaydırması korunmalıdır. Masaüstü, mobil dikey/yatay ve iki rol için açılış/kapanış kontrolü yapılmadan yeni bir geçiş yayımlama.
- Bilgi tablosu pencereleri ortak ölçülüdür; küçük giriş formları gereksiz büyütülmez. Ayarlar alt ekranları aynı pencere içinde açılır, geri dönüşte sekme ve kaydırma korunur.
- Bekleme ve hata durumları `content-state.js` üzerinden üretilir. Mevcut içerik güncellenirken gereksiz boş ekran oluşturma.
- Görsel değişikliklerde rol/yetki sınırlarını, müşteri ayrımını, kaydedilmemiş form korumasını, statü renklerini, sayfalama ve kopyalama davranışlarını koru. Canlı aktarım/iş kuyruğu kodunu tasarım amacıyla değiştirme.
- Her arayüz değişikliğini iki rolde, masaüstünde ve mobil dikey/yatay ölçülerinde doğrula. Yalnız ilgili dosyaları, karşılaştırılmış geri dönüş yedeğiyle yayımla.
- Bu standartları değiştirmek için kullanıcının yeni açık talebi gerekir. Tek bir profilde veya cihazda ayrı bir tasarım oluşturma.
- Kurulu uygulama adları `OG Yönetici Portalı` ve `OG Müşteri Portalı`; mağaza adları `Omnigo Global Yönetici Portalı` ve `Omnigo Global Müşteri Portalı` olmalıdır. Güncelleme, bildirim ve kayıtlı giriş sürekliliği için paket kimliklerini ve imza anahtarlarını koru.
- Windows kurulumunu paketli bir araçtan çalıştırırken MSIX dosya sanallaştırmasını denetle. Son kontrol normal kullanıcı bağlamında fiziksel EXE yolu, kısayol ve AppUserModelId kayıtlarıyla yapılmalı; uygulama Codex önbelleğine bağlı kalmamalıdır.

### Yeni ekranlar ve güncellemeler

- Yeni sayfa, tablo, form, pencere ve bildirimlerde ortak bileşenleri ve tasarım sisteminin yazı ailesi, boyut, renk, durum ve boşluk değerlerini kullan. Ortak varsayılanlar iki portalda mevcut müşterilere ve sonradan açılan mağazalara otomatik uygulanır.
- Mağazaya özel marka, görünüm, yetki ve kullanıcı tercihlerini koru. Görsel kabul kontrolünde yönetici ve müşteri görünümlerini masaüstü ve mobil ölçülerde, kayıtlı tercihlerle birlikte doğrula.
- Pazaryeri listelerini seçili mağazanın bağlantı verisinden üret. Bağlantı sayısı değişince yeni satır ve kartlar aynı bileşenleri, sütun sırasını ve hizmet hizalarını kullanır. Bir hizmeti eksik olan kartta sonraki hizmeti onun hizasına taşıma. Yeni düzenleri bir bağlantıyla, tam ve eksik son sırayla, mobil ve masaüstünde doğrula.

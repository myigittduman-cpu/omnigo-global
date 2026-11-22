# Ortak arayüz ve işleyiş kontrolü — 4 Kasım 2026

Bu kurallar mevcut ve yeni hesaplarda ortak uygulanır; AGENTS.md ve design-system.json temel tasarım kaynağıdır.

- Pencereler ilk görünür karede son konum ve ölçüdedir. Konuşma yenileme aynı pencereyi kullanır; gerçek kapanışta odak, sayfayı kaydırmadan eski düğmeye döner.
- Gizli menülerden kalan bağlantılar dengeli satırlara yerleşir; şirket bağlantısı yeniden oluşturulmaz.
- Siparişler masaüstünde mevcut alana sığar. Mobilde tam tablo ve yalnız tablo içinde yatay kaydırma korunur.
- Siparişler listesinde tamamlanan/teslim edilen yeşil, iptal edilen kırmızıdır. Sevkiyatın teslim edildi gösterimi yalnız gerçek kaynak teyidine dayanır. Fatura ve belge ihracatı tasarımları değişmez.
- Müşterinin kapalı durum değiştirme yetkisi tekli, toplu ve doğrudan API isteklerini birlikte engeller. Her mağazanın kayıt kimliği ve fiyatı ürüne bağlıdır.
- Manuel stok kaydı önce kalıcı, tekrar güvenli kuyruğa yazılır; yalnız ilgili yerel komut hemen tamamlanır. Aktif pazaryeri işçisi ve canlı kaynaklar değiştirilmez. Başarı, gerçek kayıt sonrası gösterilir.
- İlgilenilmesi gerekenler yalnız Omnigo'ya açıktır. Sevk bekleyişi 10 günü aşmalıdır. Kaynak sevk/iptal/iade durumunun doğrulanması takip konusunu otomatik kapatır.
- Ödeme farkı onayı yalnız takip durumunu değiştirir; beklenen ve hesaba geçen para tutarlarını değiştirmez. Satışa açacağım/açmayacağım/bildirimi sil seçenekleri yalnız takiptir; pazaryerine yazmaz ve canlı aktarımı kesmez.
- Müşteri bildirim seçim ekranında yalnız müşteri alıcılı seçenekler görünür; Omnigo alıcı ayarları ayrı korunur.
- Omnigo Admin ek şifresinde 10 dakika sınırı yoktur. Doğrulama seçili mağaza ve mevcut geçerli giriş oturumu boyunca sürer; yeni oturumda veya başka mağazada yeniden istenir.

Yayınlar: 0.9.287 ana paket; 0.9.288 oturum süresi; 0.9.289 iptal talebi kaynak takibi. Kaynaklara yazan aktarım algoritmaları, PDF ve Excel görünüşleri korunmuştur.

## Ortak görünürlük ve başlangıç şablonu

- 0.9.290–0.9.292: Tüm Türkiye teslimat seçimi sayfa başlığının sağında yer alır; mobilde aynı bileşen ekran genişliğine göre alt satıra iner. Bölgesel görünüm ve evrak şablonları korunur.
- İlgilenilmesi gerekenler, Genel Bakış içinde En Çok Satan 10 sekmesinin yanındadır ve yalnız Omnigo rolüne açıktır. Sevkiyat, ürün/pazaryeri eşleşmeleri, iptal/iade ve ödeme başlıkları ayrıdır. Her satır Omnigo / İnceleniyor gösterir.
- Bu listenin tarihli operasyon kayıtları 1 Ekim 2026 ve sonrasıdır. Ürün eşleşme uyarıları güncel stok/katalog durumundan gelir. Çözülenler görünmez; tamamlanan iş arşivi üretilmez. Kaydı sil yalnız mevcut uyarıyı bastırır. Kaynak ürün, sipariş, hakediş ve aktarım kuyruğu değişmez; kaynak koşulu değişince yeniden değerlendirilir.
- Müşteri/Kullanıcı Yetkileri içindeki İptal / İade görünürlüğü iki ayrı izindir; başlangıçta kapalıdır. Sipariş, teslimat, stok satış/hareket ayrıntıları, hakediş kalemleri ve analizde uygulanır. Şehir Dışı Sevkiyat bu görünürlük kısıtının açık istisnasıdır. Sunucu yetki kontrolü ve arayüz gösterimi birlikte uygulanır. Net satış hesaplaması değişmez.
- Müşteri analizi iki izin de kapalıyken dört özet alanını 2×2 gösterir. İzin açılırsa ilgili alan boşluk bırakmadan geri gelir. Stok işlem düğmeleri 1–5 görünür eylem için eşit sütunları doldurur; tam beş eylemin onaylı 3+2 düzeni korunur.
- Yeni müşteri şablonu, 4 Kasım 2026 tarihinde doğrulanan Vizyona genel sayfa/işlem izinlerinin bağımsız sabitidir. Başka mağazanın kullanıcı, logo, anahtar, fiyat veya ticari verisini okumaz. Yeni kullanıcılar kendi mağazalarının aynı varsayılanlarıyla başlar; yönetici sonradan özel izin tanımlayabilir.
- Stok modelinde marketplace manuel stok/satış yazımını kapatır; store pazaryeri stok ayrıntısını kapatır. both ve horoz ilgili canlı + manuel işlemleri destekler. Model uyumsuz izinler API tarafında da geçersizdir.
- Mevcut kullanıcıya özel izinler otomatik sıfırlanmaz. Genel ayar ile özel kullanıcı izni farklıysa etkin izin kullanıcı ayarında görülür. Genel ayarı mevcut hesaplara aktarmak için Yetkileri kullanıcılara uygula alanından kullanıcılar seçilir.
- Bu tur ortak web/API güncellemesidir; Android/Windows başlatıcı veya paket kimliği değişmemiştir. Yeni APK/setup ya da Google/Microsoft mağaza paketi gerektirmez.

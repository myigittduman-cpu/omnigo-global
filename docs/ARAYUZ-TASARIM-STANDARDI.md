# Ortak arayüz standardı

Kullanıcının onayladığı referans, Şehir Dışı Sevkiyat bölümündeki Yazdır, Excel’e aktar ve PDF indir düğmeleridir. Yeni ekranlarda da aynı tasarım kullanılır.

- İşlem düğmeleri `.btn` sınıfını ve `public/control-theme.css` ortak stilini kullanır: koyu lacivert zemin, ince ve ölçülü altın çerçeve, altın metin, 5 piksel köşe ve normal ağırlıklı yazı.
- Yeni ekranlara ayrı kabarık düğme, kalın yazı, büyük ikon kutusu veya gradyan eklenmez. İşlem düğmesi ile gezinme kartı birbirinden ayrıdır.
- Açılır menüler, arama ve tarih alanları aynı renk ve çerçeveyi kullanır. Klavyeyle odaklanma altın çerçeveyle görünür kalır.
- Başlık ve ilgili seçim alanı masaüstünde aynı satırda, dar ekranda taşmadan alt alta yerleşir. Kaldırılan bileşenlerin boşluğu tutulmaz.
- Mobil dokunma alanları ve mevcut erişilebilirlik davranışları korunur. Ortak stiller tablo sütunlarını veya veri işleyişini değiştirmez.
- Tasarım değişikliği; canlı aktarım, müşteri ayrımı, yetki, stok hesabı veya belge içeriği değişikliği anlamına gelmez. Bunlar ayrı kapsam ve doğrulama gerektirir.

## 31 Ekim 2026 ortak uygulama standardı

- Ortak tema ve tablo ölçeği uygulamaya aittir; hiçbir müşteri kaydı kaynak değildir. VİZYONA silinse de korunur.
- Tüm yönetici/müşteri profilleri, yeni hesaplar ve web tabanlı mobil/masaüstü uygulamalar aynı ortak dosyaları kullanır. Marka adı, logo ve veriler kopyalanmaz.
- Siparişler tablosu referansı: Segoe UI / Arial, hücre 13 px ve 1.45 satır aralığı; başlık 12 px / 500; yardımcı metin 11 px.
- Lacivert–altın buton stili korunur. Mobil işlem düğmeleri en az 44 px dokunma yüksekliğine sahiptir.
- Mobilde tablolar gerçek sütunlarıyla kalır; kartlara dönüşmez. Yalnız tablo alanı yatay kayar; sayfa yatay taşmaz. Masaüstü sevkiyat tablosu ekrana sığar.
- Pazaryeri logoları tüm ekranlarda aynı 52 × 52 px çerçevede, oranları korunarak gösterilir.
- Kişisel dil ve hareket azaltma erişilebilirlik tercihleri ortak tasarımdan bağımsızdır.
- Yeni değişiklikler müşteri veya kullanıcı adına özel CSS ile yapılmaz; ortak bileşen ve tasarım değişkenleri üzerinden uygulanır.

- Kullanıcının onayladığı özel vurgu: Stok Yönetimi tablosundaki üç stok miktarı 18 px, yatay/dikey ortalıdır; üçünün altında da adet birimi bulunur. Diğer tablo metinleri 13 px standardında kalır.

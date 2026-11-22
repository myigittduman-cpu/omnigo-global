# Yönetici üst menüsü — 0.9.51

Kullanıcının geçici olarak denemek istediği üst menü, yalnız yönetici çalışma alanında uygulanır. Müşteri panelinin sol menüsü ve iki panelin mobil alt menüsü korunur. Genel bakış, Müşteriler, Pazaryerlerim, Siparişler, Teslimat Evrakları ve Şehir Dışı Sevkiyat doğrudan görünür. Kalan altı bölüm Diğer açılır menüsündedir. Kayıtlı menü sırası her grubun içinde uygulanır; Düzenle erişimi korunur.

Yeni modül mevcut gezinme olaylarını kullanır: yetkiler, çalışma alanı seçimi ve kaydedilmemiş sevkiyat değişikliklerinin koruması değişmez. Yönetici sol menüsü DOM'dan kaldırıldığı için sevkiyatın menü aç/kapat düğmesi oluşmaz. Geniş masaüstünde üst menü kaydırırken görünür kalır; kısa ve mobil ekranlarda içeriğin alanı korunur.

## Kontroller

- JavaScript sözdizimi, mevcut görünürlük/bildirim/sıralama kontrolleri.
- PHP görünürlük ve iki HTTP test grubu: firma izolasyonu, yönetici/müşteri rolleri, CSRF ve kayıt çakışmaları.
- Yerel örnek hesaplarda ana bölüm/Diğer geçişi, menü sırasını kaydetme ve yeniden düzenleme, mobil Diğer menüsü, müşteri menüsünün korunması.
- 390, 800 ve 1440 piksel ekranlarda kontrol; örnek sevkiyat verileriyle tablo ve kaldırılan kenar menü düğmesi.

## Yayın ve geri dönüş

Yalnız `public/admin-navigation.js`, `public/admin-navigation.css`, `public/app.js` ve `public/version.json` yayımlanır. Kaynakların önceki içeriği `storage/releases/0951-before.json` ve yerel `verification/admin-navigation-0951/before.json` dosyalarında saklanır. Yayın sırasında mevcut app.js içeriği Git temel sürümüyle karşılaştırılır; değişmişse işlem durur. Yeni bağımlılıklar önce, uygulama ve sürüm bilgisi sonra yüklenir.

Geri dönüşte 0951 yedeğindeki app.js geri alınmalı ve daha yeni bir sürüm numarası yayımlanmalıdır. Yeni modüller kullanılmadan kalabilir. 0950 PTTAVM kapatma işlemi bu geri dönüşten bağımsızdır; PTTAVM açılmaz. Başka kirli çalışma dosyaları bu yayına dahil edilmez.

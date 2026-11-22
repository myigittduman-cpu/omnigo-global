# Yalnız giriş ekranında seçim ve kopyalama — 0.9.101

Kullanıcının kapsam düzeltmesi: yalnız açılış/giriş ekranında seçim ve kopyalama kapalı; giriş sonrasındaki hiçbir işlev değişmez.

- Giriş yerleşiminin metin/görsel seçimi, sağ tık kopyalama menüsü ve görsel sürüklemesi engellenir.
- Giriş alanları düzenlenebilir; metin seçme, silme, yapıştırma ve şifre yöneticisi akışı korunur.
- CSS yalnız `.login-layout` varken geçerlidir. Olay dinleyicileri kaldırılan giriş köküne bağlıdır; global çalışma alanına dinleyici eklenmez.
- Tablo kopyalama düğmeleri, Excel/PDF dosyaları, sipariş kayıtları ve oturumdan sonraki ekranlar değiştirilmedi.
- Bu bir arayüz davranışıdır; ekran görüntüsü veya geliştirici araçlarıyla erişime karşı güvenlik sınırı değildir.

Yedekler: `verification/login-content-09100/source-before.json`, `verification/login-content-09101/source-before.json`; sunucu kaynak yedekleri ilgili `storage/releases/login-content-*-before.json` dosyalarıdır. 0.9.100 ara sürümünde sayfa dışındaki yükleme düğmesi seçilebilir kaldığı için 0.9.101'de giriş açıkken gövdeye de aynı CSS uygulandı.

Canlı doğrulama: iki portal giriş başlığı `user-select:none`; yönetici girişinde Ctrl+A sonrası logo/metin/yükleme düğmesinde seçim vurgusu yok. Müşteri operatör alanı `user-select:text`; test metni yazılıp Ctrl+A/Backspace ile silindi, kaydedilmedi. Kayıtlı oturumla girişten sonra çalışma alanı başlığı `user-select:auto`. Test sekmeleri kapatıldı. Ekran kanıtı `verification/login-content-09101/login-selection.png`.

Windows: yayımdaki iki resmi 0.2.18 paketinin imzaları doğrulanarak `C:\Users\myigi\OneDrive\Desktop` altına indirildi. Kanıt `verification/windows-desktop-delivery-20261019/result.json`. Kurulum kullanıcı tarafından tamamlandıktan sonra gerçek Windows Excel/PDF Kaydet penceresi testi bekleniyor. Bu web giriş düzenlemesi için Windows paketi yeniden üretilmedi; uygulama güncel portalı açar.

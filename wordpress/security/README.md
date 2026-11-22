# WordPress güvenlik düzenlemesi — 14.10.2026

Bu kurallar yalnızca `/home/omnigogl/public_html/.htaccess` için hazırlanmıştır. Ayrı müşteri/yönetici portalı dizinlerine uygulanmaz.

- HTTPS yönlendirmesini Really Simple Security'nin `301 .htaccess redirect` seçeneği yönetir.
- `request-guard.htaccess`, erişim günlüklerinde görülen kurulum/dosya taramalarını sunucuda 403 ile reddeder. IP, ülke veya normal kullanıcı trafiğine toplu engel koymaz.
- Tarayıcıların varsayılan ikon adresleri mevcut site ikonlarına, eski `/about/` ve `/services/` bağlantıları Türkçe sayfalara yönlendirilir.
- Koruma bloğu WordPress işaretinden önce, HTTPS yönlendirmesinden sonra yer alır. WordPress ve Really Simple Security işaretlerinin içeriği korunur.
- Tarihsel `rsssl_404_notice_shown` kaydı, koruma testlerinden sonra sıfırlanmıştır. Eklentinin yeni 404 yoğunluğunu algılayan kodu ve uyarı görünürlüğü değiştirilmemiştir. Farklı bir tarama dalgası yeni uyarı oluşturabilir.

Şifreli yedek: `data/recovery/wordpress/wp-hardening-20261014.enc` ve aynı isimli JSON manifesti. AES-GCM, dosya özetleri, ZIP ve veritabanı tamamlanma işareti doğrulanmıştır. Geri çözme için mevcut DPAPI korumalı kurtarma anahtarı gerekir.

Uygulama ve kontroller: `work/wp-hardening-guard.mjs`. Test başarısız olursa önceki `.htaccess` dosyasını geri yükler. Değişiklik öncesi/sonrası dosyalar ve sonuçlar `verification/wp-hardening-2026-10-14/` altındadır.

E-posta bildirimi etkinleştirilmek istenmiş, ancak eklenti doğrulama tamamlanmadığı için bu seçeneği kapalı ve devre dışı bırakmıştır. Doğrulama tamamlandıktan sonra yeniden etkinleştirilip kontrol edilmelidir. Mevcut Google Workspace SMTP bağlantısı `smtp.gmail.com:465` kullanır. Sunucuda 465 ve 587 bağlantı denemeleri başarısızdır; IPv4 denemelerinde `Connection refused` dönmüştür. SMTP parolası, alıcı, bağlantı sunucusu ve TLS doğrulaması değiştirilmemiştir. Barındırma sağlayıcısı bağlantıyı düzelttikten sonra doğrulama e-postası yeniden gönderilmeli ve alıcı tarafından onaylanmalıdır. "Force confirm email" kullanılmamalıdır.

## Erişim korumaları — 17.10.2026

İşlem 16 Ekim UTC / 17 Ekim Türkiye saatiyle tamamlanmıştır. Kayıt dizini `verification/wp-access-2026-10-16/` olarak korunmuştur.

- `access-guard.htaccess` mevcut WordPress kurallarından önce eklenir. Güvenli içerik türü, çerçeve ve yönlendiren başlıklarını uygular; PHP sürüm başlığını kaldırır. Kullanılmayan XML-RPC, kurulum adresleri, kök readme, özel yapılandırma/günlük/Git dosyaları ve uploads altında PHP/PHTML/PHAR erişimini engeller. Admin AJAX, REST API ve normal giriş akışı korunur.
- Sayısal `author` taramasına güvenlik eklentisinin döndürdüğü genel 500 yerine sunucuda 403 döner. Yönetici giriş adresinin bulunabilir olması yetkisiz yönetim erişimi anlamına gelmez. WordPress dosya yolları gizlenmez ve dosya sürüm parametreleri bozulmaz.
- Loginizer 2.1.2 etkin: 5 hatalı denemede 15 dakika geçici engel, 5 tekrarlanan kilitlenmede 24 saat. IP kaynağı `REMOTE_ADDR`; istemcinin bildirdiği forwarding başlıkları kullanılmaz. Yeni log tablosu eklentinin standart etkinleştirmesiyle oluşturuldu; önceki log tablosu yoktu. Eski isteğe bağlı Softaculous geçişi çalıştırılmadı.
- Two Factor 0.17.0 resmi WordPress deposundan kuruldu. Yönetici TOTP/telefon eşleştirmesi henüz yapılmadı; etkin koruma olarak raporlanmamalı. Kullanıcı kendi profilinde eşleştirip kurtarma kodlarını kendisi saklamalı. Şifreler, oturumlar ve mevcut Really Simple Security ayarları korunmuştur. E-posta doğrulaması hâlâ `started`; zorla onay verilmemiştir.
- `wp-config.php` izinleri 0600 oldu. Astra 4.14.1 ve LatePoint 5.7.5 güncellendi; 12 mevcut etkin eklenti korundu. LatePoint yeniden etkinleşirken oluşturduğu tek yeni Müşteri Kabini sayfası (3091), işlem öncesi yedekte bulunmadığı doğrulanarak taslağa alındı. Önceden bulunan sayfalara dokunulmadı.

Yeni şifreli yedek: `data/recovery/wordpress/wp-access-20261016.enc` ve JSON manifesti. 20.365 dosya, 68 tablo ve 3.323 satır doğrulandı; mevcut mu-plugins dizini varsa yedeğe dahil edilir. Bu yedek tema/eklenti/medya, veritabanı, wp-config ve .htaccess kapsamındadır; WordPress çekirdeğinin tam dosya imajı değildir.

Kontroller: 13 web sayfası, 63 görsel/CSS/JS kaynağı, site haritaları, yönlendirmeler, boş görüşme formu doğrulaması ve 16 erişim/başlık kontrolü geçti. Giriş sınırlamasının 4/5 deneme sınırı, süre dolumu ve sahte forwarding başlığı davranışı kurulu eklentinin gerçek fonksiyonlarıyla salt okunur SQL örnek yanıtları üzerinde kontrol edildi; gerçek hesapla parola denemesi veya kalıcı test kaydı oluşturulmadı. İlk test, tablo işlemsel olmadığı için yazmadan durduruldu; ikinci test tamamen salt okunurdur. Geçici PHP dosyaları ve cron görevleri kaldırıldı, 7 kalıcı görev aynen korundu.

Geri alma: `.htaccess` için `verification/wp-access-2026-10-16/htaccess-before.txt`; eklenti/tema ve veritabanı için yeni şifreli yedek. Özel sunucu yedek dizinindeki `settings-before.json` önceki etkin bileşen ve Loginizer ayarlarını tutar. TOTP eşleştirmesi sonradan yapılırsa, kullanıcıya haber vermeden güvenlik yöntemini devre dışı bırakacak geri alma uygulanmamalıdır.

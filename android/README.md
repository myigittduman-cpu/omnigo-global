# Omnigo Global Portalları — Android

Android 8.0 ve üzeri için mimariden bağımsız, imzalı APK. Mevcut `public/` arayüzünü ve canlı portal API'sini kullanan ince Android kabuğudur; ekran ve iş kuralları kopyalanmaz. Windows Tauri projesi değişmez.

- `MainActivity`: güvenli WebView, pencere boşlukları, açılış/yeniden deneme ve yaşam döngüsü.
- `channels.mjs`: müşteri/yönetici paket kimliği, başlık, alan adı ve sürüm ayarları. Java kaynakları ve web arayüzü ortaktır. Her paket kendi alan adını, çerez alanını ve imza anahtarını kullanır.
- `PortalClient`: yalnızca derlenen uygulamanın alan adını uygulama içinde açar; TLS hatalarını geçmez.
- `PortalFileChooser`: müşteri logosu için Android dosya seçiciyi açar; yalnızca kullanıcının seçtiği PNG/JPEG/WebP dosyası aktarılır.
- `PortalBridge`: sadece ana çerçevedeki kesin HTTPS kaynağına tek kullanımlık kanal aktarır; her sayfa geçişinde kapatır.
- `ReportPayload` / `ReportSaver`: rapor adı/tür/boyut doğrulaması, telefonun Dosyalara Kaydet ekranı, arka planda dosya yazımı.
- `UpdateChecker`: açılışta yeni Android paketini kontrol eder; varsa indirme bildirimi gösterir. APK kurulumunu Android kullanıcı onayıyla tamamlar. Web değişiklikleri canlıdan gelir.

Yalnızca internet izni kullanılır. Depolamanın tamamına erişim, kamera, mikrofon, rehber ve APK yükleme izni istenmez. Kimlik bilgileri APK içine konmaz. Oturum portalın çerez politikasıyla yönetilir; üçüncü taraf çerezleri ve uygulama yedeği kapalıdır.

## Derleme

Müşteri: `node android/build.mjs client`

Yönetici: `node android/build.mjs admin`

Müşteri paket kimliği `com.omnigoglobal.client`, yönetici paket kimliği `com.omnigoglobal.admin` olur. İkisi aynı telefona kurulabilir. Ortak Java ad alanının `com.omnigoglobal.client` olması Android uygulama kimliğini değiştirmez; AAPT kaynak sınıfı ortak ad alanına üretilir.

Resmi JDK 21, Android Platform 35 ve Build Tools 35.0.0 araçları `work/android-toolchain` altında gerekir. Kaynak adresleri ve doğrulama bilgileri aynı klasördedir. Derleme sırası: Java kontrolleri → AAPT2 → javac → D8 → zipalign → apksigner → imza/paket doğrulaması. Gradle veya emülatör gerekmez.

APK dosyaları `../outputs/Omnigo-Android` klasörüne, sürüm bildirimleri bu klasördeki `client/latest.json` veya `admin/latest.json` konumuna çıkar. İlk müşteri sürümünden kalan kök `latest.json` tarihsel dosyadır. Güncelleme için `channels.mjs` içindeki ilgili `version` ve `versionCode` değerlerini artırın; yayımlanmış aynı sürümü yeniden dağıtmayın. İmza anahtarları `data/android-signing` altındadır; **yayımlamayın, kaybetmeyin ve güvenli yedekleyin**. Sonraki sürümleri aynı uygulamanın anahtarıyla imzalayın. Derleme çıktıları `android/build/client` ve `android/build/admin` altında ayrılır.

Gerçek telefon testi ayrıca yapılmalıdır: giriş, Beni Hatırla/Oturumumu Açık Tut, satış ekranı, tarih filtreleri, PDF/Excel/metin kaydetme ve iptal, Android geri tuşu, ekran döndürme, klavye boşlukları, bağlantı kesilmesi/yeniden deneme. Yönetici uygulamasında ayrıca müşteri seçimi, logo seçme/yükleme/iptal, destek ve pazaryeri soruları ekranları kontrol edilmelidir. Yönetici yetkileri sunucudaki oturum kontrolüyle korunur; APK yüklemek yönetici yetkisi vermez.

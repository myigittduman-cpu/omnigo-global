# Omnigo müşteri portalı

Vizyona için yerelde çalışan ilk uygulama. Eski HTML taslağı değiştirilmedi. Pazaryeri yönetimi, stok/fiyat yazma ve sipariş işlemleri bu ürünün kapsamı dışındadır.

## Çalıştırma

Node.js 24 veya üzeri gerekir; dış paket kurulumu gerekmez.

```powershell
node src/server.js
```

http://127.0.0.1:8787 adresinde ilk yönetici hesabınızı kendiniz oluşturun. Firma: Vizyona. Kanallar: Hepsiburada, n11, Pazarama, idefix, PTT AVM, Koçtaş. İlk veritabanında gerçek satış veya hakediş yoktur. Kanalın aktif olarak tanımlanması API bağlantısı kurulduğu anlamına gelmez.

Örnek verilerle ayrı inceleme alanı:

```powershell
node src/server.js --demo
```

http://127.0.0.1:8788 — Örnek yönetici: `yonetici@ornek.test`, müşteri: `musteri@ornek.test`; her iki örnek hesabın şifresi `Omnigo-Demo-2026!`. Bu hesaplar yalnızca demo.sqlite dosyasında bulunur. Gerçek sürüme taşımayın.

## Çalışan özellikler

- Sunucuda oturum, şifre özeti, CSRF kontrolü ve giriş denemesi sınırı.
- Firma bazında erişim kontrolü. Müşteri kullanıcıları şirket kimliği değiştirerek başka firmanın verisine erişemez.
- Aylık brüt satış, iptal, iade, net satış ve sipariş sayısı; kanal bazında tek dönem özeti.
- Beklenen hakediş, aktarım bildirimi, teyit edilmiş tutar/tarih/kaynak.
- Çalışma ekleme/düzenleme; dahili notlar müşteriye gönderilmez.
- Metin raporu hazırlama, taslak/yayın ayrımı, müşteri okuma ve metin indirme.
- Müşteri hesabı/firma ekleme, değişiklik geçmişi, eşzamanlı düzenleme çakışması kontrolü.
- SQLite ile kalıcı kayıt, ayrı örnek veritabanı; para tam sayı kuruş olarak saklanır.
- Modüler kuyruk bileşeninde yeniden deneme, hız sınırı beklemesi, bağlantı engeli ve yarım kalan iş kurtarma. Canlı adaptörler olmadığı için çalışan sunucuda otomatik senkronizasyon başlatılmaz.
- Favicon, 3 Ekim 2026 tarihinde omnigoglobal.com üzerindeki `cropped-omnigo-favicon-gold-o-32x32.png` dosyasının aynısıdır.

## Henüz bağlı olmayanlar

Altı pazaryerinin gerçek API bağlantıları ve hesap yetkileri, canlı barındırma, HTTPS, e-posta daveti/şifre sıfırlama, dosya/PDF yükleme henüz kurulmadı. Raporlar bu sürümde metin olarak hazırlanır. Otomatik satış veya hakediş çekildiği iddia edilmez; kaynak belirterek yönetici girişi kullanılabilir.

Bu sürüm yalnızca loopback adresinde çalışır ve localhost dışındaki Host değerlerini reddeder. Canlı yayına geçmeden önce hedef sunucu, alan adı, güvenli çerezler, yedekleme/geri yükleme, hesap kurtarma, log izleme ve pazaryeri yetkileri birlikte yapılandırılmalıdır. SQLite yerel başlangıç içindir; çoklu sunucu/yüksek trafik için PostgreSQL ve ayrı kuyruk çalışanına geçilmelidir. Bu bir üretim hazır sertifikası değildir.

## Yapı

`src/app.js`: HTTP sınırı ve yetkilendirme. `security.js`: hesap/oturum. `validation.js`: veri kuralları. `repository.js`: firma kapsamında kayıtlar ve özetler. `database.js`: şema/işlem yönetimi. `sync-queue.js`: bağımsız asenkron iş kuyruğu. `public/`: tasarım, API istemcisi, ekranlar ve formlar ayrı modüller.

## Doğrulama

```powershell
node --test tests/*.test.js
```

Testler geçici veritabanında firma izolasyonu, müşteri yazma yasağı, CSRF, dahili çalışma/taslak rapor gizliliği, oturum iptali, para hesabı, tarih/teyit doğrulaması, çakışan güncelleme, kalıcılık, giriş sınırı ve kuyruk yeniden denemelerini sınar.

## Veri yedeği

Sunucuyu durdurup `data/portal.sqlite` dosyasını güvenli bir konuma kopyalayın. Çalışırken yalnızca ana SQLite dosyasını kopyalamak yeterli değildir; çevrim içi yedekleme için SQLite backup API kullanılmalıdır. `data/` klasörünü yayınlanan statik dosyalara veya Git deposuna dahil etmeyin.


## Müşteri yönetimi (0.5)
PHP yayınına firma iletişim bilgileri, yöneticiye özel notlar, güvenli logo yükleme,
hesap güncelleme/pasifleştirme, oturum iptali ve süreli şifre kurtarma eklendi.
Yeni kurulumda şema dosyası; mevcut kurulumda `php/bin/migrate.php` veya
`php/migrations/002-customer-management.sql` kullanılır. Mevcut kayıtlar silinmez.

Logo dosyaları en fazla 1 MB ve 2048x2048 piksel kabul edilir, GD ile PNG olarak
yeniden kodlanır ve en fazla 512 piksele küçültülür. Görseller yalnızca oturum ve
firma yetkisi kontrolünden sonra sunulur. Profil notları müşteri API'sine verilmez.

E-posta işçisi her dakika `php/bin/worker.php` çalıştırır. `mail.config.php` ve
`mail-password.txt` web kökü dışında, 0600 izinli tutulmalıdır. Gerçek dosyalar
yayın paketine yeniden eklenmemeli veya sürüm kontrolüne alınmamalıdır.
SMTP: srvc78.trwww.com:465, SMTPS, doğrulanmış sertifika, PHPMailer 7.1.1.
E-posta kuyruğunda yenileme bağlantıları AES-256-GCM ile şifrelenir. Bağlantılar
30 dakika ve tek kullanım içindir. Başarılı yenileme, pasifleştirme ve adres
 değişiminde mevcut oturumlar ile eski bağlantılar iptal edilir.
Posta işçisi en çok 5 kez artan aralıklarla yeniden dener. "Gönderildi" yalnızca
posta sunucusunun kabulünü ifade eder; alıcının gelen kutusu ayrı doğrulanır.

Doğrulama: `php/tests/management.php`, `php/tests/integration.mjs` ve
`php/tests/portal-access.mjs`. Gerçek müşteriler test amacıyla değiştirilmez.
Geri dönüş paketi: deploy/customer-management-rollback.zip; ek tablolar korunur.

## Windows uygulama güncellemeleri (0.2.1)

- Müşteri ve yönetici için ayrı HTTPS manifestleri, uygulama kimlikleri ve imzalama anahtarları kullanılır.
- Açılıştan 10 saniye sonra ve her 6 saatte bir kontrol yapılır. Menüden elle kontrol edilebilir.
- Kullanıcının Güncelle seçimi sonrası imza doğrulanarak NSIS kurulumu başlatılır. Ret veya hatada mevcut sürüm çalışır.
- Büyütülmüş pencere başlangıcı ve isteğe bağlı F11 geçişi vardır. Masaüstünde PWA kurulum düğmesi oluşturulursa kaldırılır. Uzak web sayfasına yerel komut yetkisi verilmez.
- `desktop/release.cjs` iki kurulumu ve `deploy/desktop-VERSION/public/updates` yayın dosyalarını üretir. Sürümü hem Cargo.toml hem tauri.conf.json içinde artırın.
- `data/desktop-signing` içindeki iki özel anahtar yalnızca yerel kullanıcıya açıktır; kaynak kontrolüne veya sunucuya yüklenmez. Bu klasörü güvenli bir yerde yedekleyin. Anahtar kaybında mevcut kullanıcıların güveneceği yeni paket üretilemez.
- Gelecek sürümlerde önce sürüme özel EXE dosyalarını, sonra latest.json dosyalarını yayınlayın. Önceki sürüm dosyalarını koruyun. Manifest cache başlığı no-store olmalıdır.
- Güncelleme imzası Authenticode yayıncı sertifikası değildir. Windows yayıncı imzası henüz yoktur.
- Doğrulama: iki paket derlendi; doğru imza kabul, bozuk paket ret, diğer kanalın paketi ret; iki HTTPS manifesti 200; indirilen EXE dosyalarının SHA256 değerleri eşit. Kurulu uygulamada gerçek sürüm geçişi henüz denenmedi.

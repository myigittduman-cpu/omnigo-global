# Yedekleme ve kurtarma

## Kapsam

`bin/backup.php` desteklenen veritabanı tablolarını tutarlı işlem içinde okur. Sevkiyatların başvurduğu fatura/teslimat görselleri ve bekleyen mesaj işlerinin ekleri de alınır. Eksik evrak varsa başarı ilan edilmez. Başarısız işlem son başarılı yedeği silmez.

V2 kayıtları ayrı sıkıştırıp AES-256-GCM ile şifreler. Arşive özel anahtar türetme, sıralı kayıt numarası, önceki doğrulama etiketi ve zorunlu bitiş kaydı kullanılır. Bozulma, kesilme, sıra değişikliği ve sona veri ekleme reddedilir. Toplam 16 MiB sınırı kaldırılmıştır; tek kayıt 32 MiB ile sınırlıdır. Evrak parçaları 256 KiB'dir; MySQL satırları tamponlanmadan okunur. Büyük yedeklerde disk kotası ve süre yine izlenmelidir.

Eski V1 yedeklerin kurtarılması korunur. V1 fiziksel evrakları içermez. Şema değişikliğinde yedek uyumu da sınanmalıdır.

## Sunucu

```sh
/usr/local/bin/php /home/omnigogl/portal/bin/backup.php
```

Mevcut günlük 03:15 sunucu saati görevi korunur. İkinci işlem `flock` ile engellenir. Dosya önce geçici adla yazılır, şifre/şema/satır/evrak bütünlüğü doğrulanır, sonra yayımlanır. `storage/backup-status.json` son denemeyi, başarı tarihini, SHA-256 ve kayıt sayılarını gösterir. Başarısızlıkta önceki `lastSuccess` korunur.

`storage/` web kökü dışındadır; dosya izinleri 0600, klasörler 0700'dür. `backup.key` uygulama verilerini açmak için de gereklidir; yenilenmemeli veya kaybedilmemelidir. Yedekler otomatik silinmez. Saklama/temizleme politikası doğrulanmış ayrı kopyalar üzerinden uygulanmalıdır.

## Sunucu dışı kopya

`storage/backup-recovery-public.pem` varsa yedek özel aktarım parçaları da üretir. Ana yedek anahtarı RSA-OAEP ile sarılır. Özel kurtarma anahtarı sunucuya gönderilmez.

Windows'ta `data/recovery/private-key.dpapi`, oluşturulduğu hesabın DPAPI korumasındadır. Kopyalama yetkili cPanel API'sini HTTPS üzerinden kullanır; parça ve dosya SHA-256 değerleri, ardından bütün şifreli kayıtlar ve evraklar doğrulanır. Anahtar açık dosyaya yazılmaz.

```powershell
node tools/backup/pull.mjs
```

36 saatten eski veya son denemesi başarısız yedek kabul edilmez. Yerel durum `data/recovery/status.json`, hata `last-error.json` içinde tutulur. Hata önceki başarılı kopyayı silmez. cPanel erişim dosyası `data/deployment-access/cpanel-token.txt` konumunda, Git dışındadır.

Aktarım parçaları 512 KiB ham veri içerir; Base64/JSON biçimindeki dosya cPanel'in 1 MiB sınırının altında kalır. İlk başarılı kopyadan sonra `tools/backup/install-task.ps1`, gizli ve düşük yetkili Windows görevini kurar veya mevcut görevini günceller. Görev her gün 07:30'da, hesap oturumu açıldığında ve saatlik olarak kontrol eder; kaçırılan çalışmayı bilgisayar kullanılabilir olduğunda telafi eder. Geçici hatalarda 5 dakika arayla üç kez yeniden dener. Node'un tam yolu görevde saklanır; etkileşimli kabuğun PATH ayarına bağımlı değildir. Görev sonucu `Get-ScheduledTaskInfo -TaskName 'Omnigo Portal Yedek Kopyasi'` ile kontrol edilir; `LastTaskResult=0` başarılı çalışmayı belirtir.

Aynı doğrulanmış yedek zaten bilgisayardaysa yerel dosyanın boyutu ve SHA-256 değeri akış halinde kontrol edilir; aynı arşiv tekrar indirilmez. Windows süreç kilidi aynı oturumda iki kopyalamanın eşzamanlı çalışmasını engeller. İşlem veya bilgisayar beklenmedik biçimde kapanırsa işletim sistemi kilidi bırakır; kalıcı kilit dosyası sonraki yedeklemeleri durdurmaz.

Bu bilgisayar sunucudan ayrı bir kopya sağlar. Windows hesabı/cihazı kaybolursa DPAPI anahtarı açılamayabilir. İki ortamın birlikte kaybına karşı bağımsız şifreli harici depolama ve taşınabilir anahtar saklama süreci ayrıca gerekir. Bilgisayar kapalıysa yerel zamanlanmış kopyalama çalışamaz; sunucudaki günlük yedek bağımsızdır.

## Boş hedefe kurtarma

Üretimden ayrı hedefe aynı sürümün tam güncel şemasını kurun; eski temel SQL tek başına yeterli olmayabilir. Hedefe trafik ve işçi bağlamayın. Hedef evrak klasörü önceden var olmamalı; üst dizin özel ve yazılabilir olmalıdır.

```sh
php bin/restore.php --target-config=/guvenli/yeni-hedef.php --archive=/guvenli/portal.ogbackup --key-file=/guvenli/backup.key --target-storage=/guvenli/yeni-storage --confirm-empty
```

Dolu veritabanına veya mevcut evrak dizinine yazılmaz. Bozuk arşiv/SQL hatasında işlem geri alınır; yalnızca bu denemenin evrak klasörü temizlenir. V2 sütunları birebir eşleşmelidir. Kurtarma sonrası aynı anahtarı yetkili yöntemle hedef uygulamanın özel `storage/backup.key` konumuna yerleştirin; kurtarma komutu anahtarı çoğaltmaz.

Oturumlar, sıfırlama jetonları, e-posta kuyruğu ve cihaz/push teslimatları geri yüklenmez. Pazaryeri işleri engellenir, bekleyen mesajlar otomatik gönderilmez. Operatör bağlantıları doğrulayarak işçileri açmalıdır. Config, SMTP parolası ve masaüstü imzalama anahtarları kapsam dışıdır; ayrı güvenli kurtarma gerekir.

## Kontroller

- `tests/backup.php`: eski arşivler ve geri alma.
- `tests/backup-stream.php`: 27 MB üzerinde veri, evrak bütünlüğü, 64 MB bellek sınırı, yanlış anahtar/bozuk/eksik arşiv, dolu hedef, işlem geri alma.
- `tests/backup-offsite.php`: ayrı kurtarma anahtarı ve aktarım bütünlüğü.
- `verification/resilience/`: yük, regresyon ve varsa canlı MySQL prova sonuçları; Git dışındadır.

# OG İş Ortağım · Mac App Store hazırlığı

Müşteri uygulamasının Mac Catalyst hedefi `OmnigoClient` şemasını ve `com.omnigoglobal.client` paket kimliğini kullanır. İş kategorisindedir. Xcode projesi macOS 13 ve üzerini hedefler; doğrulama arşivinde Apple Silicon (`arm64`) ve Intel (`x86_64`) dilimleri aranır. Kaynaktaki sürüm 1.0.3, derleme numarası 4’tür. Yüklemeden önce App Store Connect’teki son derlemeyle karşılaştırın. ZIP, derleme kaynaklarıdır.

## Mac üzerinde doğrulama

Mac’te Xcode 26 ve Node.js 24 ile proje kökünden çalıştırın:

```sh
node ios/generate-project.mjs
node ios/build-macos.mjs verify
```

Doğrulama, müşteri testlerini Mac Catalyst üzerinde çalıştırır ve iki işlemci mimarisini içeren imzasız arşivi denetler. Depo bir GitHub çalıştırıcısına bağlandığında `iOS ve macOS bulut doğrulaması` iş akışını elle `macos` kapsamıyla başlatabilirsiniz. Sonuçlarda test kayıtları ve başarılı imzasız arşiv bulunur.

## Windows’tan bulut derlemesi

Mac kaynak ZIP’inin içeriğini özel GitHub deposunun varsayılan dalına alın. GitHub’da **Actions → iOS ve macOS bulut doğrulaması → Run workflow** yolunu izleyin ve `macos` seçin. Bu iş Apple üyeliği olmadan çalıştırılabilir. Özel depolardaki GitHub Actions kullanımı hesap planına ve kalan kotaya bağlıdır. [GitHub Actions kullanımı ve ücretlendirme](https://docs.github.com/en/actions/concepts/billing-and-usage)

Şirket üyeliği etkinleşince iki dağıtım kimliğini, her birinin özel anahtarıyla birlikte parola korumalı `.p12` olarak hazırlayın. Mac App Store Connect profili `com.omnigoglobal.client` ve üretim Push Notifications yetkisiyle uygulama dağıtım sertifikasını içermeli. Kimlikler ve profiller kuruluş hesabında yetkili kullanıcı tarafından oluşturulur. [Apple sertifika türleri](https://developer.apple.com/help/account/certificates/certificates-overview/) · [Apple dağıtım profili](https://developer.apple.com/help/account/provisioning-profiles/create-an-app-store-provisioning-profile/)

GitHub deposunda **Settings → Secrets and variables → Actions** bölümüne girin. **Variables** bölümünde `OMNIGO_APPLE_TEAM_ID` değişkenini tanımlayın. **Secrets** bölümünde aşağıdaki değerleri ekleyin. İmza sırlarının erişebildiği depoda yalnız güvendiğiniz kişilere yazma yetkisi verin. [GitHub imzalama rehberi](https://docs.github.com/en/actions/how-tos/deploy/deploy-to-third-party-platforms/sign-xcode-applications)

| Depo sırrı | Değer |
| --- | --- |
| `OMNIGO_MAC_APP_P12` | Apple Distribution veya Mac App Distribution kimliğinin Base64 kodlanmış `.p12` dosyası |
| `OMNIGO_MAC_APP_P12_PASSWORD` | Uygulama kimliği dosyasının parolası |
| `OMNIGO_MAC_INSTALLER_P12` | Mac Installer Distribution kimliğinin Base64 kodlanmış `.p12` dosyası |
| `OMNIGO_MAC_INSTALLER_P12_PASSWORD` | Yükleyici kimliği dosyasının parolası |
| `OMNIGO_MAC_APP_STORE_PROFILE` | Mac App Store Connect profilinin Base64 kodlanmış `.provisionprofile` dosyası |

Windows PowerShell’de bir dosyayı Base64 olarak panoya almak için aşağıdaki komutu dosyanın yoluyla çalıştırın. Değeri yalnızca GitHub’daki sır alanına yapıştırın.

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes('C:\imza\dosya.p12')) | Set-Clipboard
```

**Actions → Mac App Store imzalı paket → Run workflow** işlemini varsayılan dalda başlatın. Başarılı çalışmanın **Artifacts** bölümünden `OG-Is-Ortagim-macOS-App-Store` çıktısını indirin. İçinde doğrulanmış `.pkg` ve `AppStoreInfo.plist` bulunur. Aynı dizine çıkarılan bu iki dosyayı App Store Connect’e bulut Mac’te veya desteklenen 64 bit Windows 11 bilgisayarda Apple Transporter ile yükleyebilirsiniz. [Apple’ın dışa aktarma dosyaları](https://help.apple.com/xcode/mac/current/en.lproj/deva1f2ab5a2.html) · [Transporter kılavuzu](https://help.apple.com/itc/transporteruserguide/en.lproj/static.html)

## İmza ve mağaza paketi

Kuruluş üyeliği etkinleştikten sonra Xcode’a Apple hesabını ekleyin. `OmnigoClient` için kuruluş ekibini ve aynı paket kimliğinin Push Notifications yetkisini kullanın. Mac App Store için uygulama ve yükleyici dağıtım sertifikalarını hazırlayın. İmzalama erişimi ve ekip kimliği Apple hesabına bağlıdır.

```sh
export OMNIGO_APPLE_TEAM_ID='<Apple ekip kimliği>'
node ios/build-macos.mjs archive
node ios/build-macos.mjs export
```

Arşiv `ios/build/macos/OmnigoClient.xcarchive` konumuna yazılır. Dışa aktarma; yükleyici imzasını, paketin içindeki uygulamanın sürümünü, imzasını, sandbox izinlerini ve üretim APNs yetkisini denetler. Ardından imzalı `.pkg` yolunu yazdırır. Paketi Xcode Organizer veya Transporter ile doğrulayın. [Apple yükleme adımları](https://developer.apple.com/help/app-store-connect/manage-builds/upload-builds/)

## App Store Connect

1. Aynı paket kimliğiyle mevcut müşteri kaydı varsa macOS’u o kayda platform olarak ekleyin. İlk kayıt olacaksa şirket ekibinde bu paket kimliğiyle açın. [Platform ekleme](https://developer.apple.com/help/app-store-connect/create-an-app-record/add-platforms/)
2. [Mac mağaza metnini ve inceleme notunu](../store/listings/macOS-tr-TR.json) kullanın. Yalnız örnek veriye erişen inceleme hesabının giriş bilgilerini App Review Information alanına girin. Canlı mağaza verisi, müşteri bilgisi veya giriş bilgisi ekran görüntülerine girmemeli.
3. İmzalanan sürümde Mac ekranı ve pencere boyutlarını, sipariş ve stok tablolarını, fatura ve teslimat belgesi yüklemeyi, PDF/XLSX kaydetmeyi ve sistem paylaşımını deneyin. Apple cihaz bildirimleri için sunucudaki gizlilik düzeltmesini ve güncel gizlilik metnini birlikte yayınlayın. APNs üretim bağlantısı etkinse deneme hesabıyla uygulama kapalıyken genel bildirim metninin geldiğini ve doğru sayfanın açıldığını sınayın.
4. Genel bakış, stok, sevkiyat ve belge ekranlarını çalışan Mac sürümünden örnek verilerle yakalayın. Mac için 1440×900 veya 2880×1800 piksel kullanılabilir. [Apple ekran görüntüsü ölçüleri](https://developer.apple.com/help/app-store-connect/reference/app-information/screenshot-specifications/)
5. App Store Connect’te yaş derecelendirmesini, ihracat uyumluluğunu, destek ve gizlilik adreslerini doldurun. App Privacy beyanında web ekranları üzerinden hesabınıza gönderilen veya saklanan verileri de değerlendirin. Hesap bilgileri, cihaz/kurulum kimliği, sohbet, destek ve belgeler için gerçek işleme amacını ve kullanıcıyla bağlantıyı doğrulayın. Kapalı kullanıcı sohbetinin inceleme kapsamı ve erişilebilir moderasyon seçeneklerini sürümden kontrol edin. [Gizlilik beyanı](https://developer.apple.com/app-store/app-privacy-details/) · [İnceleme kuralları](https://developer.apple.com/app-store/review/guidelines/)

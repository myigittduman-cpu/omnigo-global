# Android tam ekran — 1.0.7 / sürüm kodu 8

19 Ekim 2026. Yönetici ve müşteri Android uygulamaları ortak `ImmersiveWindow` sınıfıyla üst durum çubuğunu ve alt gezinme çubuğunu gizler. İlk açılış, odağa dönüş, arka plandan dönüş, ekran yönü değişikliği ve klavye kapanışı kapsanır. Sistem çubukları Android'in kenar hareketiyle geçici olarak açılabilir. Android'in kendi izin, dosya seçme ve klavye ekranlarının görünümünü işletim sistemi belirler.

Android 11 ve üzerinde `WindowInsetsController`, Android 8–10'da mevcut platformun immersive sticky bayrakları kullanılır. Klavye gizleme çağrısı yapılmaz. Klavye ve ekran çentiği boşlukları korunur; geçici sistem çubukları sayfa yerleşimini değiştirmez. Mevcut geri hareketi ve beş saniyelik açılış animasyonu değiştirilmemiştir.

## Yedek ve kapsam

- Kaynaklar ve önceki APK'lar: `verification/android-fullscreen-107/source-before`.
- Mevcut dosyalardan yalnız `MainActivity.java` ve `android/mobile/configuration.mjs` değişti. Ortak pencere sınıfı ve bağımsız test dosyaları eklendi.
- Sunucu, veritabanı, web/Windows arayüzü, siparişler, raporlar ve bildirim kuralları değiştirilmedi.
- Paket kimlikleri ve imza anahtarları korundu; yeni izin eklenmedi. Mevcut uygulama kaldırılmadan APK ile güncellenebilir.

## Doğrulama

- İki AAB/APK derlemesi, paket kimliği/sürümü ve imzası doğrulandı.
- Bağımsız JVM testinde 57 API sözleşmesi kontrolü geçti. Android API 26, 28, 29, 30, 33 ve 36 dalları; klavye boşluğu, çentik, geç bağlanan pencere, tekrarlı olaylar ve dinleyici temizliği denetlendi. Testte kullanılan Android sınıfları taklittir; gerçek işletim sistemi/klavye testinin yerine geçmez.
- Mobil köprü, bildirim izinleri, bildirim politikası ve paket kontrollerinde 37 test geçti.
- Her uygulamada mevcut 31 yerel güvenlik/rapor kontrolü ve açılış animasyonu yaşam döngüsü testleri geçti.
- `node work/android-fullscreen-107-verify.mjs` APK özetlerini, önceki imzayı, değişmeyen izinleri ve tam ekran sınıfının gerçek paketlerde bulunmasını doğrular. Sonuç: `verification/android-fullscreen-107/result.json`.

## Telefonda kalan kontrol

Her iki uygulamayı güncelledikten sonra tamamen kapatıp açın. Üst/alt sistem çubuklarının uygulama ekranında gizlendiğini; bir giriş alanında klavyenin açılıp kapanabildiğini; ana ekrana gidip geri dönünce tam ekranın korunduğunu; PDF/Excel kaydetme ekranından dönüşün çalıştığını kontrol edin. Gerçek telefon görünümü bu çalışma sırasında doğrulanmadı.

Kaynak: https://developer.android.com/develop/ui/views/layout/immersive

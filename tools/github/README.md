# Kontrol ve GitHub kaydı

Önce ilgili işlev testlerini çalıştırın ve farkları inceleyin. Ana klasörde durum: `node tools/github/status.mjs`.

Kontrol: `node tools/github/check.mjs` (hazırlanmış dosyalar), `node tools/github/check.mjs --tracked` (son commit).

Kayıt: `node tools/github/save.mjs "Türkçe değişiklik açıklaması" -- dosya1 dosya2`.

Yardımcı açık dosya listesi ister, uzak dal farklıysa durur, mevcut hazırlanmış değişiklikleri karıştırmaz, commit ve push sonrası uzak kimliği doğrular. Tamamlanmamış veya ilgisiz değişiklikleri eklemeyin. Pre-commit/pre-push kontrolleri bilinen sır ve özel veri yollarını tarar; elle incelemenin ve işlev testlerinin yerine geçmez. Yalnız main gönderilir. Eski yerel dallar ve etiketler gönderilmez. Ek workflow yetkisi verilmemiştir. Canlı dağıtım yapılmaz.

Kurulum şablonu Tauri CLI 2.12.1 resmi kaynağından alınmıştır (Apache-2.0 / MIT):
https://github.com/tauri-apps/tauri/blob/tauri-cli-v2.12.1/crates/tauri-bundler/src/bundle/windows/nsis/installer.nsi

Özgün SHA-256: dabed59013b1d78b879a1a85bc7f2eed2993b33a9a90cdabe5946de3d3950597
Yerel fark: görünen ürün adı yenilenirken eski kurulum, kaldırma ve başlangıç kayıt anahtarlarını korur. Kısayol adı geçişi shortcut-names.nsh içindedir. install-location.nsh yalnız bilinen eski varsayılan klasörler için yeni ASCII hedefi seçer. Eski klasörü taşımaz; paketi yeni hedefe kurar, başarılı kurulumdan sonra ilgili kısayol ve başlangıç hedeflerini günceller. Eski dosyalar yerinde korunur. Özel dizinler değiştirilmez. Yarım kalmış geçiş yalnız aynı uygulama kimliğini içeren .omnigo-install-owner işaretiyle sürdürülür; başka uygulamaya ait veya işaretsiz dolu hedefe yazılmaz. Boş hedef yalnız özyinelemesiz RMDir ile temizlenebilir; dizin bağlantıları reddedilir.
Masaüstü kısayolu kurulum bölümünde ilk kurulumda ve güncellemede oluşturulur. `/NS` seçimine ve başka bir hedefe ait aynı adlı kısayola dokunulmaz.
Tauri CLI güncellenirken şablon da resmi aynı sürümle karşılaştırılmalıdır.

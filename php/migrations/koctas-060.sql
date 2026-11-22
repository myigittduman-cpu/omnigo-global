-- Yalnızca omnigogl_portal veritabanında uygulanır.
-- Mevcut kayıt türleri korunur; hiçbir kayıt silinmez.
ALTER TABLE `omnigogl_portal`.`records`
  MODIFY `kind` ENUM(
    'channels','sales','payments','tasks','reports',
    'integration_koctas','sync_koctas','order_koctas'
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL;

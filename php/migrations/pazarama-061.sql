-- Önce mevcut veritabanı yedeğini alın. Mevcut kayıtlar ve Koçtaş türleri korunur.
ALTER TABLE `omnigogl_portal`.`records`
  MODIFY `kind` ENUM(
    'channels','sales','payments','tasks','reports',
    'integration_koctas','sync_koctas','order_koctas',
    'integration_pazarama','sync_pazarama'
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL;

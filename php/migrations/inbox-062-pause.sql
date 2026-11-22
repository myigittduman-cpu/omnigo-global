-- Geri alma öncesinde cron görevi durdurulmalı ve çalışan işlem tamamlanmalıdır.
-- Sonucu belirsiz bir yanıtı yeniden göndermemek için koruyucu durdurma.
UPDATE inbox_jobs
SET state = CASE WHEN kind='reply' AND state='running' THEN 'unknown' ELSE 'failed' END,
    error='Güncelleme geri alındı. Yeniden gönderim öncesi pazaryeri kontrolü gerekiyor.',
    lease_token=NULL, lease_until=NULL
WHERE state IN ('pending','running');

<?php
declare(strict_types=1);
namespace Omnigo;
final class PushSchema
{
 public static function install(Database $db):void{
  $engine=$db->mysql()?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
  $id=$db->mysql()?'INT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
  $db->pdo->exec("CREATE TABLE IF NOT EXISTS notification_devices(
   id $id,user_id INT NOT NULL,kind VARCHAR(12) NOT NULL,device_key VARCHAR(64) NOT NULL UNIQUE,
   token_hash VARCHAR(64) NULL UNIQUE,payload TEXT NULL,credential_tag VARCHAR(64) NOT NULL,
   session_token VARCHAR(64) NOT NULL,enabled INT NOT NULL DEFAULT 1,sound INT NOT NULL DEFAULT 1,
   generation INT NOT NULL DEFAULT 1,expires BIGINT NOT NULL,last_seen BIGINT NOT NULL,
   FOREIGN KEY(user_id) REFERENCES users(id))$engine");
  $db->pdo->exec("CREATE TABLE IF NOT EXISTS push_deliveries(
   id VARCHAR(64) PRIMARY KEY,device_id INT NOT NULL,event_id INT NULL,generation INT NOT NULL,
   state VARCHAR(16) NOT NULL DEFAULT 'pending',attempts INT NOT NULL DEFAULT 0,
   next_run BIGINT NOT NULL,lease_until BIGINT NOT NULL DEFAULT 0,lease_token VARCHAR(64) NULL,
   created BIGINT NOT NULL,error VARCHAR(120) NULL,
   UNIQUE(device_id,event_id),FOREIGN KEY(device_id) REFERENCES notification_devices(id),
   FOREIGN KEY(event_id) REFERENCES order_notifications(id))$engine");
  if(!$db->mysql())$db->pdo->exec('CREATE INDEX IF NOT EXISTS push_ready ON push_deliveries(state,next_run)');
  elseif(!$db->all("SHOW INDEX FROM push_deliveries WHERE Key_name='push_ready'"))$db->pdo->exec('CREATE INDEX push_ready ON push_deliveries(state,next_run)');
 }
}


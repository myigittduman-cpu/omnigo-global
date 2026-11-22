CREATE TABLE install_state (id INT PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO install_state VALUES(1);
CREATE TABLE companies (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE users (
 id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(200) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL,
 password VARCHAR(255) NOT NULL, role ENUM('admin','client') NOT NULL, company_id INT NULL,
 FOREIGN KEY(company_id) REFERENCES companies(id), CHECK(role='admin' OR company_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE sessions (
 token CHAR(64) PRIMARY KEY, user_id INT NOT NULL, csrf CHAR(64) NOT NULL, expires BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), INDEX(expires)
) ENGINE=InnoDB;
CREATE TABLE records (
 id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL,
 kind ENUM('channels','sales','payments','tasks','reports','integration_koctas','sync_koctas','order_koctas','integration_pazarama','sync_pazarama','integration_n11','sync_n11','order_n11','stage_n11','integration_hepsiburada','sync_hepsiburada') NOT NULL,
 payload JSON NOT NULL, version INT NOT NULL DEFAULT 1, updated VARCHAR(35) NOT NULL,
 FOREIGN KEY(company_id) REFERENCES companies(id), INDEX(company_id,kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE audit (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, company_id INT NOT NULL,
 action VARCHAR(60) NOT NULL, record_id INT, before_value JSON, after_value JSON, created VARCHAR(35) NOT NULL,
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(company_id) REFERENCES companies(id), INDEX(company_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE login_attempts (attempt_key CHAR(64) PRIMARY KEY, attempt_count INT NOT NULL, reset_at BIGINT NOT NULL, INDEX(reset_at)) ENGINE=InnoDB;
CREATE TABLE jobs (
 id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, provider VARCHAR(60) NOT NULL,
 state ENUM('pending','running','blocked','failed') NOT NULL DEFAULT 'pending', attempts INT NOT NULL DEFAULT 0,
 next_run BIGINT NOT NULL, lease_until BIGINT, lease_token CHAR(64), error VARCHAR(250),
 FOREIGN KEY(company_id) REFERENCES companies(id), UNIQUE(company_id,provider), INDEX(state,next_run)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS company_profiles (company_id INT PRIMARY KEY, payload TEXT NOT NULL, logo MEDIUMTEXT, version INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS account_controls (user_id INT PRIMARY KEY, active INT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS password_resets (token_hash VARCHAR(64) PRIMARY KEY, user_id INT NOT NULL, expires BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS mail_outbox (id VARCHAR(64) PRIMARY KEY, user_id INT NOT NULL, payload MEDIUMTEXT, state VARCHAR(20) NOT NULL, attempts INT NOT NULL DEFAULT 0, next_run BIGINT NOT NULL, lease_until BIGINT NOT NULL DEFAULT 0, lease_token VARCHAR(64), created VARCHAR(35) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS application_preferences (preference_key VARCHAR(64) PRIMARY KEY, preference_value VARCHAR(255) NOT NULL, version BIGINT NOT NULL, updated BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO application_preferences(preference_key,preference_value,version,updated) VALUES('operation_mail_enabled','1',1,0);

CREATE TABLE IF NOT EXISTS inbox_threads (
 id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, provider VARCHAR(30) NOT NULL,
 remote_id VARCHAR(180) NOT NULL, payload MEDIUMTEXT NOT NULL, updated VARCHAR(35) NOT NULL,
 FOREIGN KEY(company_id) REFERENCES companies(id), UNIQUE(company_id,provider,remote_id), INDEX(updated)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS inbox_messages (
 id INT AUTO_INCREMENT PRIMARY KEY, thread_id INT NOT NULL, remote_id VARCHAR(180) NOT NULL,
 inbound INT NOT NULL, payload MEDIUMTEXT NOT NULL, created VARCHAR(35) NOT NULL,
 FOREIGN KEY(thread_id) REFERENCES inbox_threads(id), UNIQUE(thread_id,remote_id), INDEX(inbound,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS inbox_reads (
 user_id INT NOT NULL, message_id INT NOT NULL, PRIMARY KEY(user_id,message_id),
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(message_id) REFERENCES inbox_messages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS inbox_jobs (
 id VARCHAR(64) PRIMARY KEY, company_id INT NOT NULL, thread_id INT NULL, kind VARCHAR(20) NOT NULL,
 user_id INT NOT NULL, payload MEDIUMTEXT NOT NULL, state VARCHAR(20) NOT NULL,
 attempts INT NOT NULL DEFAULT 0, next_run BIGINT NOT NULL, lease_until BIGINT NULL,
 lease_token VARCHAR(64) NULL, error VARCHAR(250) NULL, created VARCHAR(35) NOT NULL,
 FOREIGN KEY(company_id) REFERENCES companies(id), FOREIGN KEY(thread_id) REFERENCES inbox_threads(id),
 FOREIGN KEY(user_id) REFERENCES users(id), INDEX(state,next_run), INDEX(company_id,kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS delivery_orders (
 id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, provider VARCHAR(30) NOT NULL,
 external_id VARCHAR(180) NOT NULL, city VARCHAR(40) NOT NULL, active INT NOT NULL,
 payload MEDIUMTEXT NOT NULL, source_hash VARCHAR(64) NOT NULL, version INT NOT NULL DEFAULT 1,
 document_id INT NULL, updated VARCHAR(35) NOT NULL, UNIQUE(company_id,provider,external_id),
 INDEX delivery_city(company_id,city,active), FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS delivery_documents (
 id INT AUTO_INCREMENT PRIMARY KEY, company_id INT NOT NULL, request_id VARCHAR(64) NOT NULL,
 request_hash VARCHAR(64) NOT NULL, city VARCHAR(40) NOT NULL, delivery_date VARCHAR(10) NOT NULL,
 order_count INT NOT NULL, payload MEDIUMTEXT NOT NULL, created_by INT NOT NULL, created VARCHAR(35) NOT NULL,
 UNIQUE(company_id,request_id), FOREIGN KEY(company_id) REFERENCES companies(id), FOREIGN KEY(created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

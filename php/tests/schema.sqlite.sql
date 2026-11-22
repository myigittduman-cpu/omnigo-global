CREATE TABLE install_state(id INTEGER PRIMARY KEY);
CREATE TABLE notification_devices(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INT NOT NULL,kind VARCHAR(12) NOT NULL,device_key VARCHAR(64) NOT NULL UNIQUE,token_hash VARCHAR(64) UNIQUE,payload TEXT,credential_tag VARCHAR(64) NOT NULL,session_token VARCHAR(64) NOT NULL,enabled INT NOT NULL DEFAULT 1,sound INT NOT NULL DEFAULT 1,generation INT NOT NULL DEFAULT 1,expires BIGINT NOT NULL,last_seen BIGINT NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id));
CREATE TABLE push_deliveries(id VARCHAR(64) PRIMARY KEY,device_id INT NOT NULL,event_id INT,generation INT NOT NULL,state VARCHAR(16) NOT NULL DEFAULT 'pending',attempts INT NOT NULL DEFAULT 0,next_run BIGINT NOT NULL,lease_until BIGINT NOT NULL DEFAULT 0,lease_token VARCHAR(64),created BIGINT NOT NULL,error VARCHAR(120),UNIQUE(device_id,event_id),FOREIGN KEY(device_id) REFERENCES notification_devices(id),FOREIGN KEY(event_id) REFERENCES order_notifications(id));
INSERT INTO install_state VALUES(1);
CREATE TABLE companies(id INTEGER PRIMARY KEY,name TEXT NOT NULL);
CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT NOT NULL UNIQUE,name TEXT NOT NULL,password TEXT NOT NULL,role TEXT NOT NULL,company_id INTEGER REFERENCES companies(id));
CREATE TABLE sessions(token TEXT PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id),csrf TEXT NOT NULL,expires INTEGER NOT NULL);
CREATE TABLE records(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL REFERENCES companies(id),kind TEXT NOT NULL,payload TEXT NOT NULL,version INTEGER NOT NULL DEFAULT 1,updated TEXT NOT NULL);
CREATE INDEX records_company_kind ON records(company_id,kind);
CREATE TABLE audit(id INTEGER PRIMARY KEY,user_id INTEGER REFERENCES users(id),company_id INTEGER NOT NULL REFERENCES companies(id),action TEXT NOT NULL,record_id INTEGER,before_value TEXT,after_value TEXT,created TEXT NOT NULL);
CREATE TABLE login_attempts(attempt_key TEXT PRIMARY KEY,attempt_count INTEGER NOT NULL,reset_at INTEGER NOT NULL);
CREATE TABLE jobs(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL REFERENCES companies(id),provider TEXT NOT NULL,state TEXT NOT NULL DEFAULT 'pending',attempts INTEGER NOT NULL DEFAULT 0,next_run INTEGER NOT NULL,lease_until INTEGER,lease_token TEXT,error TEXT,UNIQUE(company_id,provider));

CREATE TABLE IF NOT EXISTS company_profiles (company_id INT PRIMARY KEY, payload TEXT NOT NULL, logo TEXT, version INT NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS account_controls (user_id INT PRIMARY KEY, active INT NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS password_resets (token_hash VARCHAR(64) PRIMARY KEY, user_id INT NOT NULL, expires BIGINT NOT NULL);
CREATE TABLE IF NOT EXISTS mail_outbox (id VARCHAR(64) PRIMARY KEY, user_id INT NOT NULL, payload TEXT, state VARCHAR(20) NOT NULL, attempts INT NOT NULL DEFAULT 0, next_run BIGINT NOT NULL, lease_until BIGINT NOT NULL DEFAULT 0, lease_token VARCHAR(64), created VARCHAR(35) NOT NULL);
CREATE TABLE IF NOT EXISTS application_preferences (preference_key VARCHAR(64) PRIMARY KEY, preference_value VARCHAR(255) NOT NULL, version BIGINT NOT NULL, updated BIGINT NOT NULL);
INSERT OR IGNORE INTO application_preferences(preference_key,preference_value,version,updated) VALUES('operation_mail_enabled','1',1,0);

CREATE TABLE IF NOT EXISTS inbox_threads (
 id INTEGER PRIMARY KEY, company_id INT NOT NULL, provider VARCHAR(30) NOT NULL,
 remote_id VARCHAR(180) NOT NULL, payload MEDIUMTEXT NOT NULL, updated VARCHAR(35) NOT NULL,
 FOREIGN KEY(company_id) REFERENCES companies(id), UNIQUE(company_id,provider,remote_id)
);
CREATE TABLE IF NOT EXISTS inbox_messages (
 id INTEGER PRIMARY KEY, thread_id INT NOT NULL, remote_id VARCHAR(180) NOT NULL,
 inbound INT NOT NULL, payload MEDIUMTEXT NOT NULL, created VARCHAR(35) NOT NULL,
 FOREIGN KEY(thread_id) REFERENCES inbox_threads(id), UNIQUE(thread_id,remote_id)
);
CREATE TABLE IF NOT EXISTS inbox_reads (
 user_id INT NOT NULL, message_id INT NOT NULL, PRIMARY KEY(user_id,message_id),
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(message_id) REFERENCES inbox_messages(id)
);
CREATE TABLE IF NOT EXISTS inbox_jobs (
 id VARCHAR(64) PRIMARY KEY, company_id INT NOT NULL, thread_id INT NULL, kind VARCHAR(20) NOT NULL,
 user_id INT NOT NULL, payload MEDIUMTEXT NOT NULL, state VARCHAR(20) NOT NULL,
 attempts INT NOT NULL DEFAULT 0, next_run BIGINT NOT NULL, lease_until BIGINT NULL,
 lease_token VARCHAR(64) NULL, error VARCHAR(250) NULL, created VARCHAR(35) NOT NULL,
 FOREIGN KEY(company_id) REFERENCES companies(id), FOREIGN KEY(thread_id) REFERENCES inbox_threads(id),
 FOREIGN KEY(user_id) REFERENCES users(id)
);

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

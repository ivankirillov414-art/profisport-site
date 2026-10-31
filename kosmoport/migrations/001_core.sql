CREATE TABLE IF NOT EXISTS kcrm_users (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 name VARCHAR(200) NOT NULL,
 email VARCHAR(254) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 role VARCHAR(32) NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 session_version INT UNSIGNED NOT NULL DEFAULT 1,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 UNIQUE KEY kcrm_users_email (email),
 KEY kcrm_users_active_role (active,role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kcrm_setup (
 id INT NOT NULL PRIMARY KEY,
 owner_id VARCHAR(64) NULL,
 completed_at DATETIME NULL,
 CONSTRAINT kcrm_setup_owner_fk FOREIGN KEY (owner_id) REFERENCES kcrm_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kcrm_auth_rate_limits (
 bucket CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
 window_started_at DATETIME NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 blocked_until DATETIME NULL,
 updated_at DATETIME NOT NULL,
 KEY kcrm_auth_rate_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kcrm_audit (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 actor_id VARCHAR(64) NULL,
 action VARCHAR(100) NOT NULL,
 entity_type VARCHAR(64) NOT NULL,
 entity_id VARCHAR(64) NULL,
 details LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL,
 KEY kcrm_audit_time (created_at,id),
 KEY kcrm_audit_entity (entity_type,entity_id,created_at),
 KEY kcrm_audit_actor (actor_id,created_at),
 CONSTRAINT kcrm_audit_actor_fk FOREIGN KEY (actor_id) REFERENCES kcrm_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

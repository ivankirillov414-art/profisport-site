CREATE TABLE IF NOT EXISTS kcrm_partners (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 name VARCHAR(200) NOT NULL,
 kind VARCHAR(80) NOT NULL DEFAULT 'organisation',
 status VARCHAR(32) NOT NULL DEFAULT 'new',
 contact_name VARCHAR(200) NOT NULL DEFAULT '',
 phone VARCHAR(80) NOT NULL DEFAULT '',
 email VARCHAR(254) NOT NULL DEFAULT '',
 website VARCHAR(500) NOT NULL DEFAULT '',
 address VARCHAR(500) NOT NULL DEFAULT '',
 notes TEXT NOT NULL,
 created_by VARCHAR(64) NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY kcrm_partners_status (status,updated_at),
 KEY kcrm_partners_name (name),
 CONSTRAINT kcrm_partners_creator_fk FOREIGN KEY (created_by) REFERENCES kcrm_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kcrm_contacts (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 partner_id VARCHAR(64) NOT NULL,
 name VARCHAR(200) NOT NULL,
 role_title VARCHAR(150) NOT NULL DEFAULT '',
 phone VARCHAR(80) NOT NULL DEFAULT '',
 email VARCHAR(254) NOT NULL DEFAULT '',
 notes TEXT NOT NULL,
 created_by VARCHAR(64) NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY kcrm_contacts_partner (partner_id,name),
 CONSTRAINT kcrm_contacts_partner_fk FOREIGN KEY (partner_id) REFERENCES kcrm_partners(id),
 CONSTRAINT kcrm_contacts_creator_fk FOREIGN KEY (created_by) REFERENCES kcrm_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kcrm_tasks (
 id VARCHAR(64) NOT NULL PRIMARY KEY,
 title VARCHAR(240) NOT NULL,
 description TEXT NOT NULL,
 status VARCHAR(32) NOT NULL DEFAULT 'todo',
 priority VARCHAR(20) NOT NULL DEFAULT 'normal',
 assignee_id VARCHAR(64) NULL,
 partner_id VARCHAR(64) NULL,
 due_date DATE NULL,
 completed_at DATETIME NULL,
 created_by VARCHAR(64) NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY kcrm_tasks_status_due (status,due_date),
 KEY kcrm_tasks_assignee (assignee_id,status,due_date),
 KEY kcrm_tasks_partner (partner_id),
 CONSTRAINT kcrm_tasks_assignee_fk FOREIGN KEY (assignee_id) REFERENCES kcrm_users(id),
 CONSTRAINT kcrm_tasks_partner_fk FOREIGN KEY (partner_id) REFERENCES kcrm_partners(id),
 CONSTRAINT kcrm_tasks_creator_fk FOREIGN KEY (created_by) REFERENCES kcrm_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

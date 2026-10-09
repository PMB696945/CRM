-- Telecom CRM schema (MySQL 5.7+ / MariaDB 10.3+)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','agent') NOT NULL DEFAULT 'agent',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounts (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_number  VARCHAR(20) NOT NULL UNIQUE,
  name            VARCHAR(190) NOT NULL,
  type            ENUM('business','residential') NOT NULL DEFAULT 'business',
  status          ENUM('prospect','active','suspended','churned') NOT NULL DEFAULT 'prospect',
  industry        VARCHAR(100) NULL,
  company_number  VARCHAR(20) NULL,
  email           VARCHAR(190) NULL,
  phone           VARCHAR(40) NULL,
  address         VARCHAR(255) NULL,
  city            VARCHAR(100) NULL,
  postcode        VARCHAR(12) NULL,
  owner_id        INT UNSIGNED NULL,
  credit_limit    DECIMAL(10,2) NULL,
  notes           TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_accounts_status (status),
  CONSTRAINT fk_accounts_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contacts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  name        VARCHAR(150) NOT NULL,
  job_title   VARCHAR(100) NULL,
  email       VARCHAR(190) NULL,
  phone       VARCHAR(40) NULL,
  mobile      VARCHAR(40) NULL,
  is_primary  TINYINT(1) NOT NULL DEFAULT 0,
  is_billing  TINYINT(1) NOT NULL DEFAULT 0,
  notes       TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_contacts_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku            VARCHAR(40) NOT NULL UNIQUE,
  name           VARCHAR(150) NOT NULL,
  category       ENUM('mobile','broadband','sip_trunk','hosted_pbx','leased_line','hardware','other') NOT NULL,
  carrier        VARCHAR(60) NULL,
  monthly_price  DECIMAL(10,2) NOT NULL DEFAULT 0,
  setup_fee      DECIMAL(10,2) NOT NULL DEFAULT 0,
  term_months    SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  active         TINYINT(1) NOT NULL DEFAULT 1,
  description    TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A "service" is a provisioned line / circuit / seat held by a customer, with its contract.
CREATE TABLE IF NOT EXISTS services (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id         INT UNSIGNED NOT NULL,
  product_id         INT UNSIGNED NULL,
  service_type       ENUM('mobile','broadband','sip_trunk','hosted_pbx','leased_line','hardware','other') NOT NULL,
  identifier         VARCHAR(100) NOT NULL COMMENT 'MSISDN, CLI, circuit ID, serial...',
  carrier            VARCHAR(60) NULL,
  status             ENUM('pending','active','suspended','ceased') NOT NULL DEFAULT 'pending',
  monthly_price      DECIMAL(10,2) NOT NULL DEFAULT 0,
  setup_fee          DECIMAL(10,2) NOT NULL DEFAULT 0,
  start_date         DATE NULL,
  term_months        SMALLINT UNSIGNED NULL,
  contract_end_date  DATE NULL,
  install_address    VARCHAR(255) NULL,
  notes              TEXT NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_services_status (status),
  KEY idx_services_end (contract_end_date),
  KEY idx_services_identifier (identifier),
  CONSTRAINT fk_services_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_services_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tickets (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference    VARCHAR(20) NULL UNIQUE,
  account_id   INT UNSIGNED NOT NULL,
  service_id   INT UNSIGNED NULL,
  contact_id   INT UNSIGNED NULL,
  subject      VARCHAR(200) NOT NULL,
  description  TEXT NULL,
  category     ENUM('fault','billing','order','porting','cancellation','general') NOT NULL DEFAULT 'general',
  priority     ENUM('P1','P2','P3','P4') NOT NULL DEFAULT 'P3',
  status       ENUM('open','in_progress','awaiting_customer','awaiting_carrier','resolved','closed') NOT NULL DEFAULT 'open',
  assigned_to  INT UNSIGNED NULL,
  carrier_ref  VARCHAR(60) NULL,
  sla_due_at   DATETIME NULL,
  resolved_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tickets_status (status),
  CONSTRAINT fk_tickets_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_tickets_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
  CONSTRAINT fk_tickets_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
  CONSTRAINT fk_tickets_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_comments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  body        TEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_comments_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS opportunities (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id      INT UNSIGNED NOT NULL,
  title           VARCHAR(200) NOT NULL,
  opp_type        ENUM('new_business','upsell','renewal') NOT NULL DEFAULT 'new_business',
  stage           ENUM('lead','qualified','proposal','negotiation','won','lost') NOT NULL DEFAULT 'lead',
  monthly_value   DECIMAL(10,2) NOT NULL DEFAULT 0,
  one_off_value   DECIMAL(10,2) NOT NULL DEFAULT 0,
  term_months     SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  probability     TINYINT UNSIGNED NOT NULL DEFAULT 10,
  expected_close  DATE NULL,
  owner_id        INT UNSIGNED NULL,
  notes           TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_opps_stage (stage),
  CONSTRAINT fk_opps_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_opps_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activities (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  type        ENUM('call','email','meeting','note','task') NOT NULL DEFAULT 'note',
  subject     VARCHAR(200) NOT NULL,
  body        TEXT NULL,
  due_date    DATE NULL,
  done        TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_activities_account (account_id, created_at),
  CONSTRAINT fk_activities_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_activities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

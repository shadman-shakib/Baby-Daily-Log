-- Baby Daily Log (multi-family) — run once in phpMyAdmin (select your database → SQL tab → paste → Go)
-- Tables use the bl_ prefix, so they can live in the same database as Zara's tracker.

CREATE TABLE IF NOT EXISTS bl_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',  -- pending = waiting for admin approval
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One-time email codes (sign-up verification and password reset)
CREATE TABLE IF NOT EXISTS bl_codes (
  user_id INT UNSIGNED NOT NULL,
  purpose ENUM('verify','reset') NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  expires_at INT UNSIGNED NOT NULL,
  tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sent_at INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, purpose),
  FOREIGN KEY (user_id) REFERENCES bl_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bl_babies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  birth_date DATE NULL,
  birth_time TIME NULL,
  sex ENUM('F','M') NULL,                      -- for WHO growth percentiles
  ntfy_topic VARCHAR(64) NULL,                  -- phone alerts for this baby (ntfy app)
  diaper_alert_min SMALLINT UNSIGNED NOT NULL DEFAULT 135,
  feed_alert_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,       -- 0 = off
  log_notify TINYINT(1) NOT NULL DEFAULT 1,                 -- phone note when a diaper change is logged
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bl_members (
  baby_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('owner','member') NOT NULL DEFAULT 'member',
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (baby_id, user_id),
  INDEX idx_user (user_id),
  FOREIGN KEY (baby_id) REFERENCES bl_babies(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES bl_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bl_invites (
  code CHAR(8) NOT NULL PRIMARY KEY,
  baby_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (baby_id) REFERENCES bl_babies(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES bl_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bl_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  baby_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,               -- who logged it
  type ENUM('feed','diaper','weight','pump','length') NOT NULL,
  kind VARCHAR(20) NOT NULL,
  time_ms BIGINT NOT NULL,                 -- event time, ms since epoch
  amount_ml DECIMAL(6,1) NULL,             -- bottle / formula / pumped
  duration_min DECIMAL(5,1) NULL,          -- breastfeeding / pumping
  weight_g DECIMAL(10,3) NULL,             -- baby weight in grams (3 decimals, so lb/oz entries stay exact)
  length_cm DECIMAL(6,3) NULL,             -- baby length in cm
  note VARCHAR(200) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_baby_time (baby_id, time_ms),
  FOREIGN KEY (baby_id) REFERENCES bl_babies(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES bl_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bl_supplies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  baby_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  source VARCHAR(40) NOT NULL,          -- e.g. Forward, CIP, Good Neighbor
  got_date DATE NOT NULL,
  qty SMALLINT UNSIGNED NULL,           -- number of diapers (optional)
  note VARCHAR(120) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_baby_date (baby_id, got_date),
  FOREIGN KEY (baby_id) REFERENCES bl_babies(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES bl_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bl_alert_state (
  baby_id INT UNSIGNED NOT NULL,
  name VARCHAR(20) NOT NULL,
  ref_ms BIGINT NOT NULL,
  sent_ms BIGINT NOT NULL,
  PRIMARY KEY (baby_id, name),
  FOREIGN KEY (baby_id) REFERENCES bl_babies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bl_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  email VARCHAR(190) NOT NULL,
  ts INT UNSIGNED NOT NULL,
  INDEX idx_ip (ip, ts),
  INDEX idx_email (email, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

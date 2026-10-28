-- Run once in phpMyAdmin (select your database → SQL tab → paste → Go)
CREATE TABLE IF NOT EXISTS baby_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type ENUM('feed','diaper','weight','pump','length') NOT NULL,
  kind VARCHAR(20) NOT NULL,
  time_ms BIGINT NOT NULL,            -- event time, milliseconds since epoch
  amount_ml DECIMAL(6,1) NULL,        -- bottle / formula / pumped
  duration_min DECIMAL(5,1) NULL,     -- breastfeeding / pumping
  weight_g DECIMAL(10,3) NULL,        -- baby weight in grams (3 decimals)
  length_cm DECIMAL(6,3) NULL,        -- baby length in cm
  note VARCHAR(200) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_time (time_ms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

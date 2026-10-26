-- Free diaper pickups. Run once in phpMyAdmin (select the babylog database → SQL tab → paste → Go)
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

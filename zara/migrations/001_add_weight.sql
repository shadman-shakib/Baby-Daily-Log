-- Run once in phpMyAdmin (select your database → SQL tab → paste → Go)
ALTER TABLE baby_logs
  MODIFY type ENUM('feed','diaper','weight') NOT NULL,
  ADD COLUMN weight_g INT UNSIGNED NULL AFTER duration_min;

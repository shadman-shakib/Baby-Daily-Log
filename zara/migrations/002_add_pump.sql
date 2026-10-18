-- Run once in phpMyAdmin (select your database → SQL tab → paste → Go)
ALTER TABLE baby_logs
  MODIFY type ENUM('feed','diaper','weight','pump') NOT NULL;

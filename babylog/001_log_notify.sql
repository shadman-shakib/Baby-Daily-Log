-- Run once in phpMyAdmin (select the babylog database → SQL tab → paste → Go)
ALTER TABLE bl_babies ADD COLUMN log_notify TINYINT(1) NOT NULL DEFAULT 1 AFTER feed_alert_min;

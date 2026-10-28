-- Length/height logging and baby sex (for WHO growth percentiles).
-- Run once in phpMyAdmin on the babylog database, before uploading the new api.php.
-- zara.msshakib.com in shared mode uses these same tables, so this covers both sites.
ALTER TABLE bl_logs
  MODIFY type ENUM('feed','diaper','weight','pump','length') NOT NULL,
  ADD COLUMN length_cm DECIMAL(6,3) NULL AFTER weight_g;
ALTER TABLE bl_babies
  ADD COLUMN sex ENUM('F','M') NULL AFTER birth_time;

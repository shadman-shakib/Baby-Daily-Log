-- Standalone zara only (not needed in shared mode): length/height logging.
ALTER TABLE baby_logs
  MODIFY type ENUM('feed','diaper','weight','pump','length') NOT NULL,
  ADD COLUMN length_cm DECIMAL(6,3) NULL AFTER weight_g;

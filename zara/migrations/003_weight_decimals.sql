-- Standalone zara only (not needed in shared mode): keep weights exact to 3 decimals.
ALTER TABLE baby_logs MODIFY weight_g DECIMAL(10,3) NULL;

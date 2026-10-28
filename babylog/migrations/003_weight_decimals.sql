-- Keep weights exact to 3 decimals (needed for lb + oz entries like 7 lb 4.125 oz).
-- Run once in phpMyAdmin on the babylog database. Existing weights are kept.
-- zara.msshakib.com in shared mode uses this same table, so this covers both sites.
ALTER TABLE bl_logs MODIFY weight_g DECIMAL(10,3) NULL;

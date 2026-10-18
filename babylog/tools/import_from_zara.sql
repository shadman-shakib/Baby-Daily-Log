-- Copy entries from Zara's standalone tracker (baby_logs) into Baby Daily Log (bl_logs).
-- Run in phpMyAdmin from the server level (click "Server: localhost" first), SQL tab.
-- Replace the two database names and the baby/user ids. Safe to run more than once:
-- entries that are already there are skipped.

INSERT INTO CPANEL_USER_babylog.bl_logs (baby_id, user_id, type, kind, time_ms, amount_ml, duration_min, weight_g, note, created_at)
SELECT 1, 1, z.type, z.kind, z.time_ms, z.amount_ml, z.duration_min, z.weight_g, z.note, z.created_at
FROM CPANEL_USER_zara.baby_logs z
WHERE NOT EXISTS (
  SELECT 1 FROM CPANEL_USER_babylog.bl_logs x
  WHERE x.baby_id = 1 AND x.time_ms = z.time_ms AND BINARY x.type = BINARY z.type AND BINARY x.kind = BINARY z.kind
);

SELECT COUNT(*) AS entries_for_baby FROM CPANEL_USER_babylog.bl_logs WHERE baby_id = 1;

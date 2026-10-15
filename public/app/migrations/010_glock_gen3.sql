-- 010 (Michael, 2026-10-14): every Glock on the CA roster is a Gen3, so the model name says so
-- ("G17" -> "G17 Gen3"). Only Glocks that are on the roster and don't already name a generation
-- are changed, so it is safe to run on a database where some were renamed by hand. Slugs, stock
-- photos and the roster link (ca_roster_entry) are untouched. Each rename is written to the
-- activity log as "System".
INSERT INTO audit_log (username, admin_name, action, entity_type, entity_id, entity_label, summary, details)
SELECT 'system', 'System', 'edited', 'firearm', id, manufacturer || ' ' || model || ' Gen3',
       'Edited firearm (generation added to the model name, migration 010)',
       'model: ' || model || ' → ' || model || ' Gen3'
  FROM firearms
 WHERE LOWER(manufacturer) = 'glock' AND ca_rostered = 1 AND model NOT LIKE '%gen%';

UPDATE firearms
   SET model = model || ' Gen3', updated_at = datetime('now')
 WHERE LOWER(manufacturer) = 'glock' AND ca_rostered = 1 AND model NOT LIKE '%gen%';

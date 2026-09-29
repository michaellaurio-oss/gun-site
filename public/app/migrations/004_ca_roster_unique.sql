-- 004: every roster row unique. Fixes rows already downloaded the same way the import now does:
-- drop repeated entries (same page), add the caliber to model names shared by several calibers
-- (then the barrel length if still shared), and enforce uniqueness from now on.
DELETE FROM ca_roster WHERE id NOT IN (SELECT MIN(id) FROM ca_roster GROUP BY detail_path);

UPDATE ca_roster
   SET model_base = model_base || ' (' || caliber || ')',
       model      = model_base || ' (' || caliber || ')' || CASE WHEN material IS NULL OR material = '' THEN '' ELSE ' / ' || material END
 WHERE (manufacturer, model) IN (SELECT manufacturer, model FROM ca_roster GROUP BY manufacturer, model HAVING COUNT(*) > 1);

UPDATE ca_roster
   SET model_base = model_base || ' (' || barrel_length_in || '" barrel)',
       model      = model_base || ' (' || barrel_length_in || '" barrel)' || CASE WHEN material IS NULL OR material = '' THEN '' ELSE ' / ' || material END
 WHERE (manufacturer, model) IN (SELECT manufacturer, model FROM ca_roster GROUP BY manufacturer, model HAVING COUNT(*) > 1);

DROP INDEX IF EXISTS idx_ca_roster_path;
CREATE UNIQUE INDEX IF NOT EXISTS idx_ca_roster_path ON ca_roster(detail_path);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ca_roster_model ON ca_roster(manufacturer, model);

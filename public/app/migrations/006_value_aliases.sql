-- 006: one spelling per caliber / action (Michael, 2026-09-30).
-- value_aliases remembers "this spelling means that one". Firearms are updated now, and new
-- values (typed in Admin > Firearms or copied from the CA roster) are mapped when saved
-- (canonical_value() in app/helpers.php). Admin > Firearms > Tidy values adds more merges.
-- Left alone on purpose (ambiguous): ".22", ".45", "Revolver, double action".
CREATE TABLE IF NOT EXISTS value_aliases (
    field     TEXT NOT NULL CHECK (field IN ('caliber', 'action')),
    alias     TEXT NOT NULL COLLATE NOCASE,
    canonical TEXT NOT NULL,
    PRIMARY KEY (field, alias)
);

INSERT OR REPLACE INTO value_aliases (field, alias, canonical) VALUES
  ('caliber', '22 LR', '.22 LR'),
  ('caliber', '.22 LR HV', '.22 LR'),
  ('caliber', '.22 Mag', '.22 Magnum'),
  ('caliber', '.22 Win. Magnum', '.22 Magnum'),
  ('caliber', '.22 WM', '.22 Magnum'),
  ('caliber', '.22 WMR', '.22 Magnum'),
  ('caliber', '.22 WMRF', '.22 Magnum'),
  ('caliber', '22MAG', '.22 Magnum'),
  ('caliber', '.32 H&R', '.32 H&R Magnum'),
  ('caliber', '.32 H&R MAG', '.32 H&R Magnum'),
  ('caliber', '.32 Mag', '.32 H&R Magnum'),
  ('caliber', '32 H&R', '.32 H&R Magnum'),
  ('caliber', '.327 Fed Mag', '.327 Federal Magnum'),
  ('caliber', '.327 Fed. Mag.', '.327 Federal Magnum'),
  ('caliber', '.327 Magnum', '.327 Federal Magnum'),
  ('caliber', '327', '.327 Federal Magnum'),
  ('caliber', '.357 MAG', '.357 Magnum'),
  ('caliber', '357 Mag', '.357 Magnum'),
  ('caliber', '.38 special/ .357 Magnum', '.357 Magnum / .38 Special'),
  ('caliber', '.38 S&W Special', '.38 Special'),
  ('caliber', '.38 S&W SPL.', '.38 Special'),
  ('caliber', '.38 Spl', '.38 Special'),
  ('caliber', '.38 S&W Special +P', '.38 Special +P'),
  ('caliber', '.38 S&W SPL. + P', '.38 Special +P'),
  ('caliber', '.38 Special + P', '.38 Special +P'),
  ('caliber', '.38 Spl. + P', '.38 Special +P'),
  ('caliber', '.38 SPL. S&W +P', '.38 Special +P'),
  ('caliber', '.380', '.380 ACP'),
  ('caliber', '.380 Auto', '.380 ACP'),
  ('caliber', '380 AUTO', '.380 ACP'),
  ('caliber', '.44 Rem. Mag.', '.44 Magnum'),
  ('caliber', '.44 Spl', '.44 Special'),
  ('caliber', '.45 ACP/.45 Colt', '.45 ACP / .45 Colt'),
  ('caliber', '.45 Long Colt', '.45 Colt'),
  ('caliber', '.460 Magnum', '.460 S&W Magnum'),
  ('caliber', '.460 S&W', '.460 S&W Magnum'),
  ('caliber', '460 S&W Magnum', '.460 S&W Magnum'),
  ('caliber', '.500 S&W', '.500 S&W Magnum'),
  ('caliber', '10 mm', '10mm'),
  ('caliber', '10mm Auto', '10mm'),
  ('caliber', '9mm Luger', '9mm'),
  ('caliber', '9x19mm', '9mm'),
  ('caliber', '9MM', '9mm'),
  ('action', 'Revolver, DAO', 'Revolver, double action only'),
  ('action', 'Revolver, DA/SA', 'Revolver, single/double action'),
  ('action', 'Revolver, Single / Double', 'Revolver, single/double action'),
  ('action', 'Revolver, Single Action', 'Revolver, single action'),
  ('action', 'Semi-auto, striker fired', 'Semi-auto, striker-fired'),
  ('action', 'Semi-auto, SA/DA', 'Semi-auto, DA/SA hammer');

UPDATE firearms SET caliber = TRIM(caliber) WHERE caliber <> TRIM(caliber);
UPDATE firearms SET action = TRIM(action) WHERE action <> TRIM(action);
UPDATE firearms
   SET caliber = (SELECT a.canonical FROM value_aliases a WHERE a.field = 'caliber' AND a.alias = firearms.caliber)
 WHERE EXISTS (SELECT 1 FROM value_aliases a WHERE a.field = 'caliber' AND a.alias = firearms.caliber);
UPDATE firearms
   SET action = (SELECT a.canonical FROM value_aliases a WHERE a.field = 'action' AND a.alias = firearms.action)
 WHERE EXISTS (SELECT 1 FROM value_aliases a WHERE a.field = 'action' AND a.alias = firearms.action);

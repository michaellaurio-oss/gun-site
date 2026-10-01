-- Gun specs database schema + seed data (SQLite)
-- Units: lengths in inches, weights in ounces, trigger pull in pounds, prices in USD.
-- weight_oz = unloaded, no magazine unless the source didn't say (see data_notes).
-- Metric columns (_mm, _g, _kg) are calculated automatically from the inch/oz/lb columns;
-- never insert into them.
--
-- Tables:
--   firearms        one row per MODEL (the spec catalog)
--   listings        one row per physical gun for sale -> points to a firearms row
--   listing_photos  photos of a specific listed gun
--   listings_public view the website uses: available listings + key specs

PRAGMA foreign_keys = ON;
-- NULL = not yet verified from a manufacturer or retailer source.

DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS ca_roster;
DROP TABLE IF EXISTS schema_migrations;
DROP TABLE IF EXISTS contact_messages;
DROP TABLE IF EXISTS value_aliases;
DROP TRIGGER IF EXISTS trg_listings_new_offroster_ins;
DROP TRIGGER IF EXISTS trg_listings_new_offroster_upd;
DROP TRIGGER IF EXISTS trg_firearms_offroster_with_new;
DROP TRIGGER IF EXISTS trg_listings_new_unchecked_ins;
DROP TRIGGER IF EXISTS trg_listings_new_unchecked_upd;
DROP TRIGGER IF EXISTS trg_firearms_unchecked_with_new;
DROP VIEW  IF EXISTS listings_public;
DROP TABLE IF EXISTS listing_photos;
DROP TABLE IF EXISTS listings;
DROP VIEW  IF EXISTS firearms_metric;   -- older versions had a separate metric view
DROP TABLE IF EXISTS firearms;

CREATE TABLE firearms (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    slug                 TEXT NOT NULL UNIQUE,          -- URL-friendly id for the webpage
    manufacturer         TEXT NOT NULL,
    model                TEXT NOT NULL,
    category             TEXT NOT NULL CHECK (category IN ('handgun','rifle','shotgun','other')),  -- other = receivers, kits
    caliber              TEXT,
    action               TEXT,
    capacity             INTEGER,
    capacity_note        TEXT,

    -- Dimensions (inches)
    barrel_length_in     REAL,
    barrel_length_mm     REAL GENERATED ALWAYS AS (ROUND(barrel_length_in * 25.4, 1)) VIRTUAL,
    overall_length_in    REAL,
    overall_length_mm    REAL GENERATED ALWAYS AS (ROUND(overall_length_in * 25.4, 1)) VIRTUAL,
    height_in            REAL,
    height_mm            REAL GENERATED ALWAYS AS (ROUND(height_in * 25.4, 1)) VIRTUAL,
    width_in             REAL,
    width_mm             REAL GENERATED ALWAYS AS (ROUND(width_in * 25.4, 1)) VIRTUAL,
    sight_radius_in      REAL,
    sight_radius_mm      REAL GENERATED ALWAYS AS (ROUND(sight_radius_in * 25.4, 1)) VIRTUAL,
    length_of_pull_in    REAL,                          -- long guns
    length_of_pull_mm    REAL GENERATED ALWAYS AS (ROUND(length_of_pull_in * 25.4, 1)) VIRTUAL,

    -- Weight (ounces)
    weight_oz            REAL,                          -- unloaded
    weight_g             REAL GENERATED ALWAYS AS (ROUND(weight_oz * 28.3495, 0)) VIRTUAL,
    weight_empty_mag_oz  REAL,                          -- with empty magazine
    weight_empty_mag_g   REAL GENERATED ALWAYS AS (ROUND(weight_empty_mag_oz * 28.3495, 0)) VIRTUAL,
    weight_loaded_oz     REAL,                          -- with full magazine
    weight_loaded_g      REAL GENERATED ALWAYS AS (ROUND(weight_loaded_oz * 28.3495, 0)) VIRTUAL,

    -- Barrel & chamber
    barrel_material      TEXT,
    barrel_twist         TEXT,                          -- e.g. '1:16" RH'
    thread_pattern       TEXT,                          -- e.g. '1/2"-28'
    chamber_length_in    REAL,                          -- shotguns
    chamber_length_mm    REAL GENERATED ALWAYS AS (ROUND(chamber_length_in * 25.4, 1)) VIRTUAL,
    choke                TEXT,                          -- shotguns

    -- Trigger (pounds)
    trigger_pull_lb      REAL,                          -- single-action / striker / single-stage
    trigger_pull_kg      REAL GENERATED ALWAYS AS (ROUND(trigger_pull_lb * 0.453592, 2)) VIRTUAL,
    trigger_pull_da_lb   REAL,                          -- double-action pull, DA/SA guns
    trigger_pull_da_kg   REAL GENERATED ALWAYS AS (ROUND(trigger_pull_da_lb * 0.453592, 2)) VIRTUAL,

    -- California
    ca_rostered          INTEGER CHECK (ca_rostered IN (0,1)),  -- handguns only: 1 = on current CA DOJ Handgun Roster, 0 = not on roster, NULL = not checked / not applicable (long guns)
    ca_roster_checked_on TEXT,                          -- date the roster status was last verified (YYYY-MM-DD)
    ca_roster_entry      TEXT,                          -- ca_roster.detail_path this model was matched to (see ca_roster)

    -- Build
    frame_material       TEXT,
    finish               TEXT,
    stock_grip           TEXT,
    sights               TEXT,

    -- Retail
    msrp_usd             REAL,
    release_year         INTEGER,
    sku                  TEXT,
    upc                  TEXT,

    -- Media & links
    image_url            TEXT,
    product_url          TEXT,
    description          TEXT,
    source_url           TEXT,                          -- main spec source
    data_notes           TEXT,                          -- conflicts / items to verify
    updated_at           TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_firearms_category     ON firearms(category);
CREATE INDEX idx_firearms_manufacturer ON firearms(manufacturer);
CREATE INDEX idx_firearms_caliber      ON firearms(caliber);

-- ---------------------------------------------------------------
-- Listings: each physical used gun for sale
-- ---------------------------------------------------------------
CREATE TABLE listings (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    firearm_id         INTEGER NOT NULL REFERENCES firearms(id),
    stock_number       TEXT UNIQUE,                     -- required once past draft/coming_soon (see CHECK below)                     -- your own inventory tag, e.g. 'U-0001'
    title              TEXT,                            -- optional headline; defaults to make + model on the site
    status             TEXT NOT NULL DEFAULT 'draft'
                       CHECK (status IN ('draft','coming_soon','available','on_hold','pending','sold','withdrawn')),
                       -- draft: not public | coming_soon: teaser, not yet photographed/priced | available: for sale
                       -- on_hold: set aside for a buyer, may come back | pending: sale in progress | sold | withdrawn
    price_usd          REAL,                            -- asking price
    new_used           TEXT NOT NULL DEFAULT 'used' CHECK (new_used IN ('new','used')),
    consignment        INTEGER NOT NULL DEFAULT 0 CHECK (consignment IN (0,1)),  -- 1 = sold on consignment for an owner
    condition          TEXT CHECK (condition IN ('New','Like New','Excellent','Very Good','Good','Fair','Poor')),
    condition_notes    TEXT,                            -- wear, holster marks, bore condition, etc.
    est_round_count    INTEGER,
    finish_color       TEXT,                            -- if different from the model's standard finish
    modifications      TEXT,                            -- aftermarket parts, sights, trigger jobs
    magazines_included INTEGER,
    original_box       INTEGER CHECK (original_box IN (0,1)),
    included_items     TEXT,                            -- manual, case, extra grips, etc.
    description        TEXT,
    additional_comments TEXT,                           -- anything else for buyers
    internal_notes     TEXT,                            -- private: never shown on the website
    listed_at          TEXT,                            -- date it went live
    sold_at            TEXT,
    sold_price_usd     REAL,
    created_at         TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT NOT NULL DEFAULT (datetime('now')),
    CHECK (stock_number IS NOT NULL OR status IN ('draft','coming_soon'))
);

CREATE INDEX idx_listings_firearm ON listings(firearm_id);
CREATE INDEX idx_listings_status  ON listings(status);

-- California: a NEW handgun that is off the CA roster can only be sold to qualifying law
-- enforcement buyers. Such listings are allowed and shown as "LEO Sales Only" (worked out from
-- new_used + category + ca_rostered; see leo_only() in public/app/helpers.php, migration 005).

-- A NEW handgun can't go on the site while its model's roster status is unchecked (migration 007).
CREATE TRIGGER trg_listings_new_unchecked_ins
BEFORE INSERT ON listings
WHEN NEW.new_used = 'new' AND NEW.status IN ('coming_soon','available','on_hold','pending')
 AND EXISTS (SELECT 1 FROM firearms f WHERE f.id = NEW.firearm_id AND f.category = 'handgun' AND f.ca_rostered IS NULL)
BEGIN
  SELECT RAISE(ABORT, 'Check this model''s CA roster status first (Firearms > the model > CA handgun roster). A new handgun can stay a Draft until then.');
END;

CREATE TRIGGER trg_listings_new_unchecked_upd
BEFORE UPDATE OF new_used, firearm_id, status ON listings
WHEN NEW.new_used = 'new' AND NEW.status IN ('coming_soon','available','on_hold','pending')
 AND EXISTS (SELECT 1 FROM firearms f WHERE f.id = NEW.firearm_id AND f.category = 'handgun' AND f.ca_rostered IS NULL)
BEGIN
  SELECT RAISE(ABORT, 'Check this model''s CA roster status first (Firearms > the model > CA handgun roster). A new handgun can stay a Draft until then.');
END;

CREATE TRIGGER trg_firearms_unchecked_with_new
BEFORE UPDATE OF ca_rostered, category ON firearms
WHEN NEW.ca_rostered IS NULL AND NEW.category = 'handgun'
 AND EXISTS (SELECT 1 FROM listings l WHERE l.firearm_id = NEW.id AND l.new_used = 'new' AND l.status IN ('coming_soon','available','on_hold','pending'))
BEGIN
  SELECT RAISE(ABORT, 'This model has new listings on the site, so its CA roster status must stay On or Not on the roster.');
END;

CREATE TABLE listing_photos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    listing_id  INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
    file_path   TEXT NOT NULL,                          -- e.g. 'photos/U-0001/left.jpg' or a full URL
    caption     TEXT,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    is_primary  INTEGER NOT NULL DEFAULT 0 CHECK (is_primary IN (0,1))  -- main thumbnail
);

CREATE INDEX idx_listing_photos_listing ON listing_photos(listing_id, sort_order);

-- Messages from the contact form (added by app/migrations/001_contact_messages.sql)
CREATE TABLE contact_messages (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,
    email         TEXT NOT NULL,
    phone         TEXT,
    stock_number  TEXT,                                 -- the gun asked about, if any
    message       TEXT NOT NULL,
    ip_hash       TEXT,                                 -- hashed, only used for rate limiting
    emailed       INTEGER NOT NULL DEFAULT 0 CHECK (emailed IN (0,1)),
    handled       INTEGER NOT NULL DEFAULT 0 CHECK (handled IN (0,1)),
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_contact_messages_created ON contact_messages(created_at);

-- Failed admin logins (added by app/migrations/002_login_attempts.sql)
CREATE TABLE login_attempts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_hash       TEXT NOT NULL,
    attempted_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_login_attempts ON login_attempts(ip_hash, attempted_at);

-- Local copy of the CA DOJ Handgun Roster (added by app/migrations/003_ca_roster.sql).
-- Filled by Admin > CA roster > Refresh; firearms.ca_roster_entry points at detail_path.
CREATE TABLE ca_roster (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    detail_path      TEXT NOT NULL,          -- roster page of the model, e.g. /firearms/handgun/grp (unique, stable id)
    manufacturer     TEXT NOT NULL,          -- as listed by DOJ, e.g. 'Sturm, Ruger & Co.'
    model            TEXT NOT NULL,          -- as listed, without the court-order asterisk; "(caliber)" added when a name is shared by several calibers
    model_base       TEXT NOT NULL,          -- part before ' / ' (the material)
    material         TEXT,
    gun_type         TEXT,                   -- Pistol / Revolver
    barrel_length_in REAL,
    caliber          TEXT,
    expires_on       TEXT,                   -- YYYY-MM-DD
    court_order      INTEGER NOT NULL DEFAULT 0 CHECK (court_order IN (0,1)),  -- * = added by court order (Boland v. Bonta)
    imported_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE UNIQUE INDEX idx_ca_roster_path ON ca_roster(detail_path);        -- one row per roster page (repeats dropped on import)
CREATE UNIQUE INDEX idx_ca_roster_model ON ca_roster(manufacturer, model);  -- names shared by several calibers get "(caliber)" added
CREATE INDEX idx_ca_roster_make ON ca_roster(manufacturer);

-- Database updates already included in this file (app/migrate.php skips these).
CREATE TABLE schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT (datetime('now')));
INSERT INTO schema_migrations (version) VALUES ('001'), ('002'), ('003'), ('004'), ('005'), ('006'), ('007');

-- What the website shows: guns currently for sale, with their key specs
CREATE VIEW listings_public AS
SELECT l.id AS listing_id, l.stock_number,
       COALESCE(l.title, f.manufacturer || ' ' || f.model) AS title,
       l.status, l.new_used, l.price_usd, l.condition, l.condition_notes, l.est_round_count,
       COALESCE(l.finish_color, f.finish) AS finish,
       l.modifications, l.magazines_included, l.original_box, l.included_items,
       l.description AS listing_description, l.additional_comments, l.listed_at,
       (SELECT p.file_path FROM listing_photos p WHERE p.listing_id = l.id
         ORDER BY p.is_primary DESC, p.sort_order LIMIT 1) AS primary_photo,
       f.id AS firearm_id, f.slug, f.manufacturer, f.model, f.category, f.caliber, f.action,
       f.capacity,
       CASE WHEN f.capacity > 10 THEN '(10 round limit in CA)' END AS ca_capacity_note,
       f.ca_rostered, f.ca_roster_checked_on, f.barrel_length_in, f.barrel_length_mm, f.overall_length_in, f.overall_length_mm,
       f.weight_oz, f.weight_g, f.sights, f.msrp_usd,
       f.description AS model_description
FROM listings l
JOIN firearms f ON f.id = l.firearm_id
WHERE l.status IN ('coming_soon','available','on_hold','pending');


INSERT INTO firearms (slug, manufacturer, model, category, caliber, action, capacity, capacity_note,
  barrel_length_in, overall_length_in, height_in, width_in, sight_radius_in, length_of_pull_in,
  weight_oz, weight_empty_mag_oz, weight_loaded_oz,
  barrel_material, barrel_twist, thread_pattern, chamber_length_in, choke,
  trigger_pull_lb, trigger_pull_da_lb,
  frame_material, finish, stock_grip, sights,
  msrp_usd, release_year, sku, upc,
  image_url, product_url, description, source_url, data_notes) VALUES

('cz-75-sp-01', 'CZ', '75 SP-01', 'handgun', '9mm Luger', 'Semi-auto, DA/SA hammer', 18, 'Double-stack magazine',
  4.6, 8.15, 5.79, 1.46, NULL, NULL,
  40.7, NULL, NULL,
  'Cold hammer-forged steel', NULL, NULL, NULL, NULL,
  3.3, 10.3,
  'Steel', 'Black polycoat', 'Rubber grip panels', 'Fixed night sights',
  650.00, NULL, NULL, NULL,
  NULL, NULL,
  'The CZ 75 SP-01 takes the classic CZ 75 and builds it for hard use. An all-steel frame, low bore axis and extended beavertail keep it flat and steady shot after shot, while an 18-round magazine, night sights and an integral accessory rail make it as ready for duty as it is for the range. Designed as a military and police sidearm, it is a pistol you can feel the accuracy in.',
  'https://www.gtdist.com/cz-usa-sp01-9mm-pistol-da-sa-4-7-bbl-w-rail.html',
  'Weight: distributor lists 40.7 oz, Firearms Bulletin 38.5 oz. Trigger pulls and $650 MSRP are from Firearms Bulletin (secondary source) - verify with CZ.'),

('cz-75-d-compact', 'CZ', '75 D PCR Compact', 'handgun', '9mm Luger', 'Semi-auto, DA/SA hammer with decocker', 14, 'Some sources list 15; 10-rd version for restricted states',
  3.75, 7.24, 5.03, 1.38, NULL, NULL,
  27.5, NULL, NULL,
  'Hammer-forged steel', NULL, NULL, NULL, NULL,
  NULL, NULL,
  'Forged aluminum alloy', 'Matte black polycoat', 'Rubberized grip panels', 'Fixed three-dot',
  695.00, NULL, NULL, NULL,
  NULL, NULL,
  'All the feel of a CZ 75 in a package built to carry. A forged aluminum frame keeps weight to about 27 ounces, and the decocker lets you carry hammer-down for a smooth double-action first shot followed by crisp single-action follow-ups. Small enough to conceal, big enough to shoot well, with 14 rounds of 9mm on board.',
  'https://www.americanrifleman.org/content/the-cz-75-d-pcr-compact/',
  'CZ product page unreachable. Dimensions, weight and $695 MSRP from American Rifleman review (date unknown; other sources quote $544-$799). Firearms Bulletin lists 26 oz.'),

('mossberg-500-retrograde', 'Mossberg', '500 Retrograde', 'shotgun', '12 gauge', 'Pump action', 5, '5+1',
  18.5, 39.5, NULL, NULL, NULL, NULL,
  108.0, NULL, NULL,
  NULL, NULL, NULL, 3.0, 'Cylinder bore (fixed)',
  NULL, NULL,
  'Aluminum receiver', 'Matte blued', 'Walnut stock, corncob-style forend', 'Front bead',
  504.00, NULL, '50429', '015813504294',
  NULL, 'https://www.mossberg.com/500-retrograde-50429.html',
  'A throwback to the classic pump guns of decades past, the 500 Retrograde pairs walnut furniture and a corncob forend with a deep blued finish. Underneath is the proven Mossberg 500 action, with dual extractors, twin action bars and steel-to-steel lockup, plus a handy 18.5-inch cylinder-bore barrel and a top safety either hand can reach. Old-school looks, dependable pump-action performance.',
  'https://www.kygunco.com/product/mossberg-50429-500-retrograde-pump-12-gauge-18.5-51',
  'Overall length: 39.5" (KYGunCo, TTAG) vs 36.37" (Gun City NZ). MSRP $504 is the 2020 launch price; TTAG lists $519. Length of pull not found.'),

('marlin-1894-trapper-357', 'Marlin (Ruger)', 'Trapper Series Model 1894', 'rifle', '.357 Magnum / .38 Special', 'Lever action', 8, '8 rds .357 Mag or 9 rds .38 Spl; tubular magazine',
  16.1, 33.25, NULL, NULL, NULL, 13.38,
  100.8, NULL, NULL,
  'Cold hammer-forged stainless steel, 6 grooves', '1:16" RH', '1/2"-28', NULL, NULL,
  NULL, NULL,
  'Stainless steel', 'Matte stainless', 'Black laminate', 'Skinner Sights adjustable peep rear, blade front',
  1599.00, NULL, '70452', '736676704521',
  'https://www.marlinfirearms.com/prodimages/70452/hero.jpg', 'https://www.marlinfirearms.com/s/model_70452/',
  'Short, light and built for real-world use, the Marlin 1894 Trapper puts a stainless, cold hammer-forged 16.1-inch barrel on a quick-handling lever gun. It shoots both .357 Magnum and .38 Special, wears adjustable Skinner peep sights and is threaded 1/2"-28 for a muzzle device. Weather-stable laminate furniture and an oversized lever loop make it as at home in the truck as in the woods.',
  'https://www.marlinfirearms.com/s/model_70452/',
  'Also offered in .44 Mag and 10mm (separate model numbers). Launch MSRP was $1,279; current listing shows $1,599.'),

('glock-19-gen3', 'Glock', 'G19 Gen3', 'handgun', '9mm Luger', 'Semi-auto, striker (Safe Action)', 15, 'Accepts longer Glock 9mm magazines',
  4.02, 7.36, 5.04, 1.26, NULL, NULL,
  21.16, 23.63, 30.16,
  NULL, '1:9.84"', NULL, NULL, NULL,
  5.5, NULL,
  'Polymer', 'Black', 'Polymer frame with finger grooves', 'White dot front, white U-notch rear',
  NULL, 1998, NULL, NULL,
  NULL, NULL,
  'The Glock 19 Gen3 is the compact that set the standard. Big enough to shoot well, small enough to carry, it holds 15 rounds of 9mm in a pistol that weighs just over 21 ounces empty. Simple Safe Action operation, an accessory rail and Glock''s reputation for reliability make it a go-to for carry, home defense and the range alike.',
  'https://www.midwestgunworks.com/page/mgwi/prod/ui1950203',
  'Some retailers list overall length as 6.85" (older published figure). Trigger pull and twist from Academy. MSRP and sight radius not yet verified.');

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

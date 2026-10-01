-- 007 (code review 2026-09-30):
-- 1. A NEW handgun can't go on the site (coming soon / available / on hold / pending) while its
--    model's CA roster status is unchecked (NULL). It can stay a Draft. Checked models are either
--    on the roster, or off it and shown as "LEO Sales Only" (migration 005).
-- 2. A model with new listings on the site can't be reset to "not checked".
-- 3. The public view no longer includes the consignment flag (not shown publicly).
CREATE TRIGGER IF NOT EXISTS trg_listings_new_unchecked_ins
BEFORE INSERT ON listings
WHEN NEW.new_used = 'new' AND NEW.status IN ('coming_soon','available','on_hold','pending')
 AND EXISTS (SELECT 1 FROM firearms f WHERE f.id = NEW.firearm_id AND f.category = 'handgun' AND f.ca_rostered IS NULL)
BEGIN
  SELECT RAISE(ABORT, 'Check this model''s CA roster status first (Firearms > the model > CA handgun roster). A new handgun can stay a Draft until then.');
END;

CREATE TRIGGER IF NOT EXISTS trg_listings_new_unchecked_upd
BEFORE UPDATE OF new_used, firearm_id, status ON listings
WHEN NEW.new_used = 'new' AND NEW.status IN ('coming_soon','available','on_hold','pending')
 AND EXISTS (SELECT 1 FROM firearms f WHERE f.id = NEW.firearm_id AND f.category = 'handgun' AND f.ca_rostered IS NULL)
BEGIN
  SELECT RAISE(ABORT, 'Check this model''s CA roster status first (Firearms > the model > CA handgun roster). A new handgun can stay a Draft until then.');
END;

CREATE TRIGGER IF NOT EXISTS trg_firearms_unchecked_with_new
BEFORE UPDATE OF ca_rostered, category ON firearms
WHEN NEW.ca_rostered IS NULL AND NEW.category = 'handgun'
 AND EXISTS (SELECT 1 FROM listings l WHERE l.firearm_id = NEW.id AND l.new_used = 'new' AND l.status IN ('coming_soon','available','on_hold','pending'))
BEGIN
  SELECT RAISE(ABORT, 'This model has new listings on the site, so its CA roster status must stay On or Not on the roster.');
END;

DROP VIEW IF EXISTS listings_public;
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

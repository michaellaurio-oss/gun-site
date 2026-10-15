-- 011 (Michael, 2026-10-14): CA exempts single-action revolvers (Ruger Blackhawk, Vaquero, Single-Six...)
-- from the handgun roster. ca_exempt = 1 marks such a model. It is kept together with ca_rostered = 0
-- ("not on the roster"), so every existing rule still holds; the exempt flag only changes how it is
-- described and means a NEW one is NOT "LEO Sales Only" (anyone eligible may buy it).
ALTER TABLE firearms ADD COLUMN ca_exempt INTEGER NOT NULL DEFAULT 0 CHECK (ca_exempt IN (0,1));

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
       f.id AS firearm_id, f.slug, f.manufacturer, f.model, f.category,
       COALESCE(l.spec_caliber, f.caliber) AS caliber,
       COALESCE(l.spec_action, f.action) AS action,
       COALESCE(l.spec_capacity, f.capacity) AS capacity,
       CASE WHEN COALESCE(l.spec_capacity, f.capacity) > 10 THEN '(10 round limit in CA)' END AS ca_capacity_note,
       f.ca_rostered, f.ca_exempt, f.ca_roster_checked_on,
       COALESCE(l.spec_barrel_length_in, f.barrel_length_in) AS barrel_length_in,
       CASE WHEN l.spec_barrel_length_in IS NOT NULL THEN ROUND(l.spec_barrel_length_in * 25.4, 1) ELSE f.barrel_length_mm END AS barrel_length_mm,
       COALESCE(l.spec_overall_length_in, f.overall_length_in) AS overall_length_in,
       CASE WHEN l.spec_overall_length_in IS NOT NULL THEN ROUND(l.spec_overall_length_in * 25.4, 1) ELSE f.overall_length_mm END AS overall_length_mm,
       COALESCE(l.spec_weight_oz, f.weight_oz) AS weight_oz,
       CASE WHEN l.spec_weight_oz IS NOT NULL THEN ROUND(l.spec_weight_oz * 28.3495, 0) ELSE f.weight_g END AS weight_g,
       COALESCE(l.spec_sights, f.sights) AS sights, f.msrp_usd,
       (l.spec_caliber IS NOT NULL
        OR l.spec_action IS NOT NULL
        OR l.spec_capacity_note IS NOT NULL
        OR l.spec_barrel_material IS NOT NULL
        OR l.spec_barrel_twist IS NOT NULL
        OR l.spec_thread_pattern IS NOT NULL
        OR l.spec_choke IS NOT NULL
        OR l.spec_frame_material IS NOT NULL
        OR l.spec_stock_grip IS NOT NULL
        OR l.spec_sights IS NOT NULL
        OR l.spec_capacity IS NOT NULL
        OR l.spec_barrel_length_in IS NOT NULL
        OR l.spec_overall_length_in IS NOT NULL
        OR l.spec_height_in IS NOT NULL
        OR l.spec_width_in IS NOT NULL
        OR l.spec_sight_radius_in IS NOT NULL
        OR l.spec_length_of_pull_in IS NOT NULL
        OR l.spec_chamber_length_in IS NOT NULL
        OR l.spec_weight_oz IS NOT NULL
        OR l.spec_weight_empty_mag_oz IS NOT NULL
        OR l.spec_weight_loaded_oz IS NOT NULL
        OR l.spec_trigger_pull_lb IS NOT NULL
        OR l.spec_trigger_pull_da_lb IS NOT NULL) AS specs_modified,
       f.description AS model_description
FROM listings l
JOIN firearms f ON f.id = l.firearm_id
WHERE l.status IN ('coming_soon','available','on_hold','pending');

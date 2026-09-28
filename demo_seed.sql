-- DEMO DATA ONLY: made-up sample listings so the site can be tried out.
-- Run after schema.sql:  php dev/build_db.php demo
-- Stock numbers, prices, conditions and roster flags below are illustrative, not real inventory
-- and not verified against the CA DOJ roster.

PRAGMA foreign_keys = ON;

UPDATE firearms SET ca_rostered = 1, ca_roster_checked_on = '2026-09-01' WHERE slug IN ('glock-19-gen3', 'cz-75-d-compact');
UPDATE firearms SET ca_rostered = 0, ca_roster_checked_on = '2026-09-01' WHERE slug = 'cz-75-sp-01';

INSERT INTO listings (firearm_id, stock_number, status, price_usd, new_used, condition, condition_notes, est_round_count,
                      modifications, magazines_included, original_box, included_items, description, additional_comments,
                      internal_notes, listed_at)
SELECT f.id, v.stock, v.status, v.price, v.nu, v.cond, v.notes, v.rounds, v.mods, v.mags, v.box, v.incl, v.descr, v.comments,
       'DEMO internal note: this text must never appear on the public site.', v.listed
FROM (
  SELECT 'glock-19-gen3' AS slug, 'D-1001' AS stock, 'available' AS status, 499 AS price, 'used' AS nu, 'Very Good' AS cond,
         'Light holster wear on the slide edges. Bore bright and clean. Frame shows normal handling marks.' AS notes, 800 AS rounds,
         NULL AS mods, 3 AS mags, 1 AS box, 'Three factory magazines, loader, cable lock, manual.' AS incl, NULL AS descr,
         'Recently cleaned and function-checked.' AS comments, '2026-09-20' AS listed
  UNION ALL SELECT 'glock-19-gen3', 'D-1002', 'available', 599, 'new', 'New', 'New in box, unfired.', 0,
         NULL, 3, 1, 'Three factory magazines, loader, cable lock, manual.', NULL, NULL, '2026-09-25'
  UNION ALL SELECT 'glock-19-gen3', 'D-1003', 'on_hold', 525, 'used', 'Excellent', 'Very minor wear at the muzzle. Bore excellent.', 300,
         'AmeriGlo tritium night sights.', 2, 1, 'Two magazines, original case.', NULL, NULL, '2026-09-10'
  UNION ALL SELECT 'glock-19-gen3', 'D-1012', 'available', 399, 'used', 'Fair', 'Finish worn at the muzzle and slide serrations; scratches on the frame. Mechanically sound, bore good.', 3000,
         NULL, 1, 0, 'One magazine.', NULL, 'Priced to move. A solid shooter.', '2026-08-28'
  UNION ALL SELECT 'cz-75-sp-01', 'D-1004', 'available', 699, 'used', 'Excellent', 'Light wear on the slide release and muzzle crown. Bore bright.', 500,
         NULL, 2, 1, 'Two magazines, factory case, manual.', NULL, NULL, '2026-09-22'
  UNION ALL SELECT 'cz-75-sp-01', 'D-1005', 'pending', 629, 'used', 'Good', 'Holster wear on the slide and frame rails. Bore good with light fouling.', 2000,
         'Cajun Gun Works trigger kit, extended safety.', 2, 0, 'Two magazines.', NULL, NULL, '2026-09-01'
  UNION ALL SELECT 'cz-75-d-compact', 'D-1006', 'available', 579, 'used', 'Like New', 'No visible wear. Test-fired only per the previous owner.', 50,
         NULL, 2, 1, 'Two magazines, factory case, manual, cleaning rod.', NULL, NULL, '2026-09-18'
  UNION ALL SELECT 'cz-75-d-compact', NULL, 'coming_soon', NULL, 'used', NULL, NULL, NULL,
         NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-27'
  UNION ALL SELECT 'mossberg-500-retrograde', 'D-1008', 'available', 429, 'used', 'Very Good', 'Small handling marks on the walnut stock. Bluing excellent. Bore bright.', 250,
         NULL, NULL, 0, 'Cable lock.', NULL, NULL, '2026-09-15'
  UNION ALL SELECT 'mossberg-500-retrograde', 'D-1009', 'available', 504, 'new', 'New', 'New in box.', 0,
         NULL, NULL, 1, 'Original box, manual, cable lock.', NULL, NULL, '2026-09-26'
  UNION ALL SELECT 'marlin-1894-trapper-357', 'D-1010', 'available', 1349, 'used', 'Excellent', 'A few light marks on the laminate stock. Action smooth. Bore excellent.', 200,
         'Leather sling and QD swivel studs added.', NULL, 1, 'Original box, manual.', NULL, NULL, '2026-09-12'
  UNION ALL SELECT 'marlin-1894-trapper-357', 'D-1011', 'coming_soon', NULL, 'used', NULL, NULL, NULL,
         NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-24'
  UNION ALL SELECT 'glock-19-gen3', 'D-1013', 'draft', 450, 'used', 'Good', 'Draft listing: should not appear on the site.', NULL,
         NULL, NULL, NULL, NULL, NULL, NULL, NULL
  UNION ALL SELECT 'mossberg-500-retrograde', 'D-1014', 'sold', 450, 'used', 'Very Good', 'Sold listing: should not appear on the site.', NULL,
         NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-01'
) v
JOIN firearms f ON f.slug = v.slug;

-- Demo photos (labelled placeholders committed in public/assets/demo/). Real photos go in photos/<STOCK#>/.
INSERT INTO listing_photos (listing_id, file_path, caption, sort_order, is_primary)
SELECT l.id, 'assets/demo/' || p.f || '.jpg', p.c, p.o, p.o = 1
FROM listings l
JOIN (SELECT 'left' AS f, 'Left side' AS c, 1 AS o UNION ALL SELECT 'right', 'Right side', 2 UNION ALL SELECT 'top', 'Top', 3
      UNION ALL SELECT 'bore', 'Bore and muzzle', 4 UNION ALL SELECT 'included', 'Included items', 5) p
WHERE l.stock_number IN ('D-1001', 'D-1010');

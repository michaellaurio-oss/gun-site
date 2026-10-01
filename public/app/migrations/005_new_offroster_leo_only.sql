-- 005: new off-roster handguns are allowed and shown as "LEO Sales Only" (Michael, 2026-09-30).
-- Removes the rules that refused them. The site works out "LEO Sales Only" from
-- new_used = 'new' + category = 'handgun' + ca_rostered = 0 (leo_only() in app/helpers.php).
DROP TRIGGER IF EXISTS trg_listings_new_offroster_ins;
DROP TRIGGER IF EXISTS trg_listings_new_offroster_upd;
DROP TRIGGER IF EXISTS trg_firearms_offroster_with_new;

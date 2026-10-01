<?php
// Public listing queries. Everything public reads from the listings_public view,
// which never exposes listings.internal_notes.

function public_listings(): array
{
    return db()->query(
        "SELECT * FROM listings_public ORDER BY COALESCE(listed_at, '') DESC, listing_id DESC"
    )->fetchAll();
}

/** Listed guns per new/used and type, e.g. ['new' => ['handgun' => 3], 'used' => [...]], in handgun/rifle/shotgun/other order. */
function stocked_types(): array
{
    $out = [];
    $rows = db()->query(
        "SELECT new_used, category, COUNT(*) AS n FROM listings_public GROUP BY new_used, category
          ORDER BY CASE category WHEN 'handgun' THEN 1 WHEN 'rifle' THEN 2 WHEN 'shotgun' THEN 3 ELSE 4 END"
    );
    foreach ($rows as $r) {
        $out[$r['new_used']][$r['category']] = (int)$r['n'];
    }
    return $out;
}

/** Listed "LEO Sales Only" guns: new handguns whose model is off the CA roster (see leo_only()). */
function leo_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM listings_public WHERE new_used = 'new' AND category = 'handgun' AND ca_rostered = 0")->fetchColumn();
}

function find_public_listing(?string $stock, ?int $id): ?array
{
    if ($stock !== null && $stock !== '') {
        $st = db()->prepare('SELECT * FROM listings_public WHERE stock_number = ?');
        $st->execute([$stock]);
    } elseif ($id) {
        $st = db()->prepare('SELECT * FROM listings_public WHERE listing_id = ?');
        $st->execute([$id]);
    } else {
        return null;
    }
    return $st->fetch() ?: null;
}

/** Model specs for the detail page (public columns only; no data_notes / sourcing). */
function firearm_specs(int $firearmId): array
{
    $st = db()->prepare(
        'SELECT caliber, action, capacity, capacity_note,
                barrel_length_in, barrel_length_mm, overall_length_in, overall_length_mm,
                height_in, height_mm, width_in, width_mm, sight_radius_in, sight_radius_mm,
                length_of_pull_in, length_of_pull_mm,
                weight_oz, weight_g, weight_empty_mag_oz, weight_empty_mag_g, weight_loaded_oz, weight_loaded_g,
                barrel_material, barrel_twist, thread_pattern, chamber_length_in, chamber_length_mm, choke,
                trigger_pull_lb, trigger_pull_kg, trigger_pull_da_lb, trigger_pull_da_kg,
                frame_material, finish, stock_grip, sights, category
           FROM firearms WHERE id = ?'
    );
    $st->execute([$firearmId]);
    return $st->fetch() ?: [];
}

/** "Stock photo" tag with the hover/focus note. $class positions it (cards, gun page, compare page). */
function stock_photo_tag(string $class = ''): string
{
    return '<span class="stock-tag ' . e($class) . '">' . tooltip('Stock photo' . icon('info', 12), STOCK_PHOTO_NOTE, 'stock-tag-btn') . '</span>';
}

function listing_photos(int $listingId): array
{
    $st = db()->prepare(
        'SELECT file_path, caption FROM listing_photos WHERE listing_id = ? ORDER BY is_primary DESC, sort_order, id'
    );
    $st->execute([$listingId]);
    return $st->fetchAll();
}

function listing_url(array $l): string
{
    return $l['stock_number'] !== null && $l['stock_number'] !== ''
        ? url('gun.php', ['stock' => $l['stock_number']])
        : url('gun.php', ['id' => $l['listing_id']]);
}

/** Escaped "a / b" value that only wraps at the slash. */
function nowrap_pair(string $s): string
{
    return implode(' / ', array_map(fn($p) => '<span class="nw">' . e($p) . '</span>', explode(' / ', $s)));
}

/** Flat card/filter record used by listing cards and the inventory JSON. */
function card_data(array $l): array
{
    $isNew = $l['new_used'] === 'new';
    // A NEW gun with no photos of its own shows the model's stock photo (never for used guns).
    $photo = thumb_url($l['primary_photo']);
    $stock = $photo === null && $isNew ? stock_photo_url($l['slug'], true) : null;
    return [
        'id'        => (int)$l['listing_id'],
        'url'       => listing_url($l),
        'photo'     => $photo ?? $stock,
        'stockPhoto' => $stock !== null,
        'make'      => $l['manufacturer'],
        'model'     => $l['title'] !== $l['manufacturer'] . ' ' . $l['model'] ? $l['title'] : $l['model'],
        'category'  => $l['category'],
        'type'      => type_label($l['category']),
        'stock'     => $l['stock_number'] ?? '',
        'caliber'   => $l['caliber'] ?? '',
        'capacity'  => capacity_text($l['capacity']),
        'overTen'   => $l['ca_capacity_note'] !== null,
        'barrel'    => length_pair($l['barrel_length_in'], $l['barrel_length_mm']),
        'weight'    => weight_pair($l['weight_oz'], $l['category']),
        'newused'   => $isNew ? 'New' : 'Used',
        'condition' => $l['condition'] ?? '',
        'roster'    => roster_label($l['category'], $l['ca_rostered']),
        'leo'       => leo_only($l['new_used'], $l['category'], $l['ca_rostered']),
        'mods'      => trim((string)$l['modifications']) !== '' ? 'Modified' : 'Factory original',
        'status'    => $l['status'],
        'price'     => $l['price_usd'] !== null ? (float)$l['price_usd'] : null,
        'priceText' => $l['price_usd'] !== null ? money($l['price_usd']) : ($l['status'] === 'coming_soon' ? 'Price TBA' : 'Call for price'),
        'listed'    => $l['listed_at'] ?? '',
    ];
}

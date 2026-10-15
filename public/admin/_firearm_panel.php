<?php
// Read-only "Firearm details" panel for the listing editor: what the picked firearm brings with it.
// Rendered on the page and again by firearm-info.php when the pick changes.

function firearm_panel_html(int $firearmId): string
{
    $st = db()->prepare('SELECT * FROM firearms WHERE id = ?');
    $st->execute([$firearmId]);
    $f = $st->fetch();
    if (!$f) {
        return '<p class="muted">Pick a firearm to see its details.</p>';
    }
    $isHandgun = $f['category'] === 'handgun';
    $dash = '<span class="muted">—</span>';
    $row = function (string $label, ?string $html) use ($dash): string {
        return '<div><dt>' . e($label) . '</dt><dd>' . ($html === null || $html === '' ? $dash : $html) . '</dd></div>';
    };
    $len = fn($in, $mm) => $in === null ? null : e(num($in) . ' in / ' . num($mm, 0) . ' mm');
    $text = fn($v) => ($v === null || trim((string)$v) === '') ? null : e((string)$v);

    $cap = $f['capacity'] === null ? null : e(num($f['capacity'], 0) . ($f['capacity'] > 10 ? ' (10 round limit in CA)' : ''));
    $weight = $f['weight_oz'] === null ? null : e($isHandgun
        ? num($f['weight_oz'], 1) . ' oz / ' . num($f['weight_g'], 0) . ' g'
        : num($f['weight_oz'] / 16, 2) . ' lb / ' . num($f['weight_oz'] * 0.0283495, 2) . ' kg');

    $roster = roster_label((string)$f['category'], $f['ca_rostered']);
    $rosterHtml = e($roster);
    if ($isHandgun && $f['ca_rostered'] !== null && $f['ca_roster_checked_on']) {
        $rosterHtml .= ' <span class="muted">(checked ' . e($f['ca_roster_checked_on']) . ')</span>';
    }
    if ($isHandgun && $f['ca_rostered'] !== null && (int)$f['ca_rostered'] === 0) {
        $rosterHtml .= ' <span class="muted">— new = LEO Sales Only</span>';
    }

    $missing = [];
    foreach (['caliber' => 'caliber', 'action' => 'action', 'capacity' => 'capacity', 'barrel_length_in' => 'barrel length',
              'overall_length_in' => 'overall length', 'weight_oz' => 'weight', 'frame_material' => 'frame material'] as $k => $label) {
        if ($f[$k] === null || trim((string)$f[$k]) === '') {
            $missing[] = $label;
        }
    }
    if ($isHandgun && $f['ca_rostered'] === null) {
        $missing[] = 'CA roster status';
    }
    if (strpos((string)$f['data_notes'], 'Caliber is a placeholder') !== false) {
        $missing[] = 'caliber is a placeholder';
    }

    $stock = stock_photo_url($f['slug'], true);
    $out = '<div class="fpanel-head">';
    if ($stock) {
        $out .= '<img class="fpanel-img" src="' . e($stock) . '" alt="" width="96" height="72">';
    }
    $out .= '<div><strong>' . e($f['manufacturer'] . ' ' . $f['model']) . '</strong> <span class="muted">· ' . e(type_label($f['category'])) . '</span><br>'
        . '<a href="' . e(url('admin/model.php', ['id' => $f['id']])) . '">Edit this firearm</a>'
        . ($stock ? '' : ' <span class="muted">· no stock photo</span>') . '</div></div>';
    $out .= '<dl class="fpanel-grid">'
        . $row('Caliber', $text($f['caliber'])) . $row('Action', $text($f['action']))
        . $row('Capacity', $cap) . $row('Barrel', $len($f['barrel_length_in'], $f['barrel_length_mm']))
        . $row('Overall length', $len($f['overall_length_in'], $f['overall_length_mm'])) . $row('Weight', $weight)
        . $row('Frame', $text($f['frame_material'])) . $row('Finish', $text($f['finish']))
        . $row('Sights', $text($f['sights'])) . $row('CA roster', $rosterHtml)
        . '</dl>';
    $out .= $missing
        ? '<p class="fpanel-missing">Not filled in on the firearm: ' . e(implode(', ', $missing)) . '.</p>'
        : '<p class="fpanel-ok muted">All the main specs are filled in.</p>';
    return $out;
}

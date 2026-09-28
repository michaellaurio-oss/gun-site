<?php
// Formatting and small shared helpers.

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Site-relative URL under the base path, e.g. url('inventory.php', ['type' => 'handgun']). */
function url(string $path = '', array $query = []): string
{
    $u = config('base_url') . ltrim($path, '/');
    $query = array_filter($query, fn($v) => $v !== null && $v !== '');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

function asset(string $path): string
{
    $file = __DIR__ . '/../' . $path;
    $v = is_file($file) ? filemtime($file) : 0;
    return url($path) . '?v=' . $v;
}

/** Photo path from listing_photos: a full URL, or a path relative to the site root. */
function photo_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    return preg_match('~^https?://~i', $path) ? $path : url($path);
}

/** Number without trailing zeros: 4.60 -> "4.6", 1154 -> "1,154". */
function num($n, int $maxDecimals = 2): string
{
    $s = number_format((float)$n, $maxDecimals, '.', ',');
    if (strpos($s, '.') !== false) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return $s;
}

function money($usd): string
{
    if ($usd === null || $usd === '') {
        return '';
    }
    $usd = (float)$usd;
    return '$' . number_format($usd, floor($usd) == $usd ? 0 : 2);
}

/** "4.02 in / 102 mm" (card) — the metric figure is rounded to whole mm. */
function length_pair($inches, $mm): string
{
    if ($inches === null) {
        return '';
    }
    return num($inches) . ' in / ' . num($mm, 0) . ' mm';
}

/** Weight for cards: ounces and grams for handguns, pounds and kilograms for long guns. */
function weight_pair($oz, ?string $category): string
{
    if ($oz === null) {
        return '';
    }
    if ($category === 'handgun') {
        return num($oz, 1) . ' oz / ' . num(round($oz * 28.3495), 0) . ' g';
    }
    return num($oz / 16, 2) . ' lb / ' . num($oz * 0.0283495, 2) . ' kg';
}

function type_label(string $category): string
{
    return ['handgun' => 'Handgun', 'rifle' => 'Rifle', 'shotgun' => 'Shotgun', 'other' => 'Other'][$category] ?? ucfirst($category);
}

function capacity_text($capacity, bool $long = false): string
{
    if ($capacity === null) {
        return '';
    }
    return $capacity . ($long ? ((int)$capacity === 1 ? ' round' : ' rounds') : ' rds');
}

/** Public status badges: label, colours and tooltip (from CLAUDE.md). */
function status_info(string $status): ?array
{
    $phone = shop('phone');
    $all = [
        'available'   => ['Available',    '#1F5A40', '#E4EFE9', "Ready to buy today. Call $phone or use \"Ask about this gun\" to get started."],
        'on_hold'     => ['On hold',      '#7A4F00', '#FBEED3', "A buyer has asked us to set this gun aside. Holds sometimes fall through, so call $phone or check back soon."],
        'pending'     => ['Sale pending', '#5B3F8C', '#EFE9F7', "A sale is in progress and this gun is waiting on transfer paperwork. Call $phone and we will let you know if it comes back."],
        'coming_soon' => ['Coming soon',  '#1F4E79', '#E3EDF7', "This gun is being inspected and photographed. Call $phone to be notified when it is listed, or check back soon."],
    ];
    if (!isset($all[$status])) {
        return null;
    }
    [$label, $fg, $bg, $tip] = $all[$status];
    return ['label' => $label, 'fg' => $fg, 'bg' => $bg, 'tip' => $tip];
}

const OFF_ROSTER_NOTE = 'Not on current CA handgun roster';
const CA_CAPACITY_NOTE = '(10 round limit in CA)';

/** Roster facet value for a listing. */
function roster_label(string $category, $caRostered): string
{
    if ($category !== 'handgun') {
        return 'Not required (long guns)';
    }
    if ($caRostered === null) {
        return 'Not yet checked';
    }
    return (int)$caRostered === 1 ? 'On roster' : 'Off roster';
}

/** Hover/focus tooltip. $trigger is trusted HTML; $tip is plain text. */
function tooltip(string $trigger, string $tip, string $class = '', string $style = ''): string
{
    static $n = 0;
    $id = 'tip-' . (++$n);
    return '<span class="tip">'
        . '<button type="button" class="tip-trigger ' . e($class) . '"' . ($style ? ' style="' . e($style) . '"' : '')
        . ' aria-describedby="' . $id . '">' . $trigger . '</button>'
        . '<span role="tooltip" id="' . $id . '" class="tip-bubble">' . e($tip) . '</span></span>';
}

function icon(string $name, int $size = 18, string $extra = ''): string
{
    $paths = [
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'arrow'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'info'    => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
        'check'   => '<path d="M5 12l5 5 9-10"/>',
        'close'   => '<path d="M6 6l12 12M18 6L6 18"/>',
        'prev'    => '<path d="M15 6l-6 6 6 6"/>',
        'next'    => '<path d="M9 6l6 6-6 6"/>',
        'chevron' => '<path d="M6 9l6 6 6-6"/>',
        'phone'   => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'photo'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="M21 16l-5-5-8 8"/>',
        'zoom'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5M11 8v6M8 11h6"/>',
        'camera'  => '<path d="M4 7h3l2-3h6l2 3h3v12H4z"/><circle cx="12" cy="13" r="3.5"/>',
        'graded'  => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h9"/>',
        'ruler'   => '<path d="M3 17l14-14 4 4L7 21H3z"/><path d="M7 13l2 2M10 10l2 2M13 7l2 2"/>',
        'menu'    => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    ];
    return '<svg class="icon ' . e($extra) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

function phone_link(string $class = '', string $label = ''): string
{
    $label = $label !== '' ? $label : e(shop('phone'));
    $tel = shop('phone_tel');
    return $tel !== ''
        ? '<a class="' . e($class) . '" href="tel:' . e($tel) . '">' . $label . '</a>'
        : '<span class="' . e($class) . '">' . $label . '</span>';
}

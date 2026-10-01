<?php
// CA DOJ Handgun Roster: download, parse, store in ca_roster, and match to firearms.
// Roster status is a legal matter (new handguns must be on it), so only exact name matches
// are applied automatically; everything else is suggested for a person to confirm.

const ROSTER_URL = 'https://oag.ca.gov/firearms/certified-handguns/search';
const ROSTER_SITE = 'https://oag.ca.gov';

/** Download the roster page (about 1 MB). Throws RuntimeException on failure. */
function roster_download(): string
{
    $c = curl_init(ROSTER_URL);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; gun shop roster check)',
    ]);
    $html = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err = curl_error($c);
    curl_close($c);
    if ($html === false || $code !== 200) {
        throw new RuntimeException('Could not download the roster from the DOJ website (' . ($err ?: 'HTTP ' . $code) . ').');
    }
    return $html;
}

/** Parse the roster table into rows. Throws if the page doesn't look like the roster. */
function roster_parse(string $html): array
{
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML($html);
    $x = new DOMXPath($doc);
    $rows = [];
    foreach ($x->query('//table//tr') as $tr) {
        $td = $tr->getElementsByTagName('td');
        if ($td->length < 6) {
            continue;
        }
        $cell = fn(int $i) => trim(preg_replace('/\s+/u', ' ', $td->item($i)->textContent));
        $model = $cell(1);
        $court = strpos($model, '*') !== false;
        $model = trim(str_replace('*', '', $model));
        [$base, $material] = array_pad(array_map('trim', explode(' / ', $model, 2)), 2, null);
        $link = $td->item(1)->getElementsByTagName('a');
        $path = $link->length ? (string)parse_url($link->item(0)->getAttribute('href'), PHP_URL_PATH) : '';
        $barrel = preg_match('/[\d.]+/', $cell(3), $m) ? (float)$m[0] : null;
        $exp = null;
        if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{2,4})$~', $cell(5), $m)) {
            $exp = sprintf('%04d-%02d-%02d', strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3], $m[1], $m[2]);
        }
        $rows[] = [
            'detail_path' => $path !== '' ? $path : 'row:' . $cell(0) . '|' . $model,
            'manufacturer' => $cell(0), 'model' => $model, 'model_base' => $base, 'material' => $material,
            'gun_type' => $cell(2), 'barrel_length_in' => $barrel, 'caliber' => $cell(4),
            'expires_on' => $exp, 'court_order' => $court ? 1 : 0,
        ];
    }
    if (count($rows) < 100) {
        throw new RuntimeException('That page doesn\'t look like the full roster (found ' . count($rows) . ' handguns).');
    }
    return $rows;
}

/**
 * Make every row unique and usable:
 * - the DOJ table sometimes lists the same entry twice (same page): keep the first;
 * - the same model certified in several calibers shares one name: add the caliber,
 *   e.g. "PX4 Storm Type G (9mm) / Steel, Polymer" (then the barrel length if still not unique).
 * Brackets are ignored by matching, so these still match a firearm named "PX4 Storm Type G".
 */
function roster_dedupe(array $rows): array
{
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        if (!isset($seen[$r['detail_path']])) {
            $seen[$r['detail_path']] = true;
            $out[] = $r;
        }
    }
    $tag = function (array &$r, string $extra) {
        $r['model_base'] .= ' (' . $extra . ')';
        $r['model'] = $r['model_base'] . ($r['material'] !== null && $r['material'] !== '' ? ' / ' . $r['material'] : '');
    };
    foreach ([fn($r) => $r['caliber'], fn($r) => $r['barrel_length_in'] !== null ? num($r['barrel_length_in']) . '" barrel' : null] as $extra) {
        $groups = [];
        foreach ($out as $i => $r) {
            $groups[strtolower($r['manufacturer'] . '|' . $r['model'])][] = $i;
        }
        foreach ($groups as $idx) {
            if (count($idx) > 1) {
                foreach ($idx as $i) {
                    $x = $extra($out[$i]);
                    if ($x !== null && $x !== '') {
                        $tag($out[$i], $x);
                    }
                }
            }
        }
    }
    return $out;
}

/** Replace ca_roster with fresh rows, then re-check firearms. Returns a summary. */
function roster_import(PDO $db, array $rows): array
{
    $rows = roster_dedupe($rows);
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM ca_roster');
        $ins = $db->prepare('INSERT INTO ca_roster (detail_path, manufacturer, model, model_base, material, gun_type, barrel_length_in, caliber, expires_on, court_order)
                             VALUES (:detail_path, :manufacturer, :model, :model_base, :material, :gun_type, :barrel_length_in, :caliber, :expires_on, :court_order)');
        foreach ($rows as $r) {
            $ins->execute($r);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();   // keep the previous roster copy rather than a half-imported one
        throw $e;
    }
    return ['rows' => count($rows)] + roster_recheck($db);
}

/**
 * After an import: firearms already matched to an entry that's still listed get today's check
 * date. Matched entries that disappeared are counted and flagged on the roster page; nothing
 * is set on or off the roster without a person confirming it.
 */
function roster_recheck(PDO $db): array
{
    $still = $db->prepare("UPDATE firearms SET ca_roster_checked_on = ? WHERE ca_rostered = 1 AND ca_roster_entry IN (SELECT detail_path FROM ca_roster)");
    $still->execute([date('Y-m-d')]);
    return ['confirmed' => $still->rowCount(), 'dropped' => count(roster_dropped($db))];
}

/** Firearms marked on-roster whose roster entry is no longer listed. */
function roster_dropped(PDO $db): array
{
    return $db->query("SELECT * FROM firearms WHERE ca_rostered = 1 AND ca_roster_entry IS NOT NULL
                        AND ca_roster_entry NOT IN (SELECT detail_path FROM ca_roster) ORDER BY manufacturer, model")->fetchAll();
}

// ---------------- matching ----------------

function roster_compact(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

/** Manufacturer key shared by roster names and shop names ("Sturm, Ruger & Co." and "Ruger" -> "ruger"). */
function roster_make_key(string $make): string
{
    $s = strtolower($make);
    $s = preg_replace('/\(imported by[^)]*\)/', '', $s);
    $s = preg_replace('/,?\s*(llc|inc\.?|co\.?|corp\.?|corporation|usa)\b/', ' ', $s);
    $k = roster_compact($s);
    $alias = [
        'sturmruger' => 'ruger', 'ceskazbrojovkacz' => 'cz', 'ceskazbrojovka' => 'cz', 'kahrarms' => 'kahr',
        'armscorprecision' => 'rockislandarmory', 'armscor' => 'rockislandarmory', 'ria' => 'rockislandarmory',
        'heritagemanufacturing' => 'heritage', 'fabriquenationale' => 'fn', 'fnamerica' => 'fn', 'fnherstal' => 'fn',
        'henryrepeatingarm' => 'henry', 'henryrepeatingarms' => 'henry', 'centuryarms' => 'century', 'archonfirearms' => 'archon',
        'staccato2011' => 'staccato', 'europeanamericanarmory' => 'eaa', 'sigsauer' => 'sig', 'sig' => 'sig',
        'smithwesson' => 'smithwesson', 'sw' => 'smithwesson', 'marlinruger' => 'marlin', 'standardmanufacturing' => 'standardmanufacturing',
    ];
    return $alias[$k] ?? $k;
}

/** Friendly manufacturer name for a roster name, preferring a name already used in firearms. */
function roster_display_make(PDO $db, string $rosterMake): string
{
    $key = roster_make_key($rosterMake);
    foreach ($db->query('SELECT DISTINCT manufacturer FROM firearms')->fetchAll(PDO::FETCH_COLUMN) as $mine) {
        if (roster_make_key($mine) === $key) {
            return $mine;
        }
    }
    $nice = ['ruger' => 'Ruger', 'cz' => 'CZ', 'kahr' => 'Kahr', 'rockislandarmory' => 'Rock Island Armory', 'heritage' => 'Heritage',
             'fn' => 'FN', 'henry' => 'Henry', 'century' => 'Century Arms', 'archon' => 'Archon', 'staccato' => 'Staccato',
             'eaa' => 'EAA', 'sig' => 'Sig Sauer'];
    if (isset($nice[$key])) {
        return $nice[$key];
    }
    return trim(preg_replace('/\s*\(imported by[^)]*\)|,?\s*(LLC|Inc\.?)$/i', '', $rosterMake));
}

/**
 * Strip what isn't part of the model name: SKU / catalog numbers ("SKU 13323", "05450", "13747"),
 * maker codes ("229R-9-BSS-CA", "HCP9379BOSPCA", "OR-14278"), parentheses, colours, "Model",
 * and Glock's "G" prefix ("G19" = roster "19").
 */
function roster_clean_model(string $model, string $make = ''): string
{
    $s = ' ' . $model . ' ';
    $s = preg_replace('/\bSKU\s*\S+/i', ' ', $s);
    $s = preg_replace('/\([^)]*\)/', ' ', $s);
    $s = preg_replace('/\b[A-Z0-9]+(?:-[A-Z0-9]+){2,}\b/', ' ', $s);        // 229R-9-BSS-CA, 238-380-TSS-CA
    $s = preg_replace('/\b(?=[A-Z0-9]*\d)(?=[A-Z0-9]*[A-Z])[A-Z0-9]{8,}\b/', ' ', $s);  // HCP9379BOSPCA
    $s = preg_replace('/[-\s]\d{5}\b|\b0\d{3,4}\b|\bOR-\d+\b/i', ' ', $s);  // 13747, 05450, -14278, OR-14278
    $s = preg_replace('/\b(?:model|black|od|fde|flat dark earth|green|grey|gray|tan|coyote|two[- ]tone|stainless|blued?|satin|matte|internal lock)\b/i', ' ', $s);
    if (roster_make_key($make) === 'glock') {
        $s = preg_replace('/\bG(?=\d)/i', '', $s);
    }
    return trim(preg_replace('/\s+/', ' ', $s));
}

function roster_model_key(string $model, string $make = ''): string
{
    return roster_compact(roster_clean_model($model, $make));
}

function roster_tokens(string $model, string $make = ''): array
{
    // Split on punctuation and between letters and digits, so "686-6" and "686" share "686".
    preg_match_all('/[a-z]+|\d+/', strtolower(roster_clean_model($model, $make)), $m);
    return array_values(array_diff(array_unique($m[0]), ['gen', 'the', 'and', 'with']));
}

/** Precompute match keys for roster rows (call once, pass the result to roster_matches). */
function roster_prepare(array $roster): array
{
    foreach ($roster as &$r) {
        $r['_make'] = roster_make_key($r['manufacturer']);
        $r['_key'] = roster_model_key($r['model_base'], $r['manufacturer']);
        $r['_tokens'] = roster_tokens($r['model_base'], $r['manufacturer']);
    }
    return $roster;
}

/** Caliber for comparing with the roster: ".45 AUTO" = ".45 ACP", "9MM" = "9mm Luger" = "9x19mm". '' if unknown. */
function roster_caliber_key(?string $caliber): string
{
    $c = strtolower((string)canonical_value('caliber', $caliber));
    $c = str_replace(['luger', 'parabellum', '9x19', 'auto', 'spl', 'mag.', 'mag'], ['', '', '9mm', 'acp', 'special', 'magnum', 'magnum'], $c);
    $c = str_replace('magnumnum', 'magnum', $c);
    return preg_replace('/[^a-z0-9+]/', '', $c);
}

/** Colour / finish words in a model name ("FDE", "Black", "Stainless"...), normalised and sorted. */
function roster_finish_words(string $model): array
{
    $map = ['flat dark earth' => 'fde', 'two tone' => 'twotone', 'two-tone' => 'twotone', 'grey' => 'gray', 'blue' => 'blued'];
    preg_match_all('/\b(?:black|od|fde|flat dark earth|green|grey|gray|tan|coyote|two[- ]tone|stainless|blued?|satin|matte)\b/i', $model, $m);
    $out = array_values(array_unique(array_map(fn($w) => $map[strtolower($w)] ?? strtolower($w), $m[0])));
    sort($out);
    return $out;
}

/**
 * Best roster rows for a firearm: [['row' => ..., 'score' => 0-100], ...], best first.
 * 100 ("Exact", the only score "Confirm all" acts on) = same make and model name AND the same
 * caliber AND no colour / finish difference, because the roster certifies specific variants.
 * Same name but a different or unknown caliber, or a different finish, scores 90 ("Possible"):
 * a person has to confirm it.
 */
function roster_matches(array $firearm, array $roster, int $limit = 3): array
{
    $make = roster_make_key($firearm['manufacturer']);
    $mk = roster_model_key($firearm['model'], $firearm['manufacturer']);
    $mt = roster_tokens($firearm['model'], $firearm['manufacturer']);
    $fCal = roster_caliber_key($firearm['caliber'] ?? null);
    $fFinish = roster_finish_words($firearm['model']);
    $out = [];
    foreach ($roster as $r) {
        if (($r['_make'] ?? roster_make_key($r['manufacturer'])) !== $make) {
            continue;
        }
        $rk = $r['_key'] ?? roster_model_key($r['model_base'], $r['manufacturer']);
        if ($mk !== '' && $rk === $mk) {
            $sameCaliber = $fCal !== '' && $fCal === roster_caliber_key($r['caliber'] ?? null);
            $sameFinish = $fFinish === roster_finish_words($r['model_base'] ?? $r['model']);   // model_base: without the frame material
            $score = $sameCaliber && $sameFinish ? 100 : 90;
        } elseif ($mk !== '' && $rk !== '' && (strpos($rk, $mk) === 0 || strpos($mk, $rk) === 0)) {
            $score = 60 + (int)(30 * min(strlen($mk), strlen($rk)) / max(strlen($mk), strlen($rk)));
        } else {
            // Share of *your* model's words found in the roster name, minus a little for extra roster words.
            $rt = $r['_tokens'] ?? roster_tokens($r['model_base'], $r['manufacturer']);
            $common = count(array_intersect($mt, $rt));
            $score = ($common && $mt) ? (int)(58 * $common / count($mt)) - 2 * max(0, count($rt) - $common) : 0;
        }
        if ($score >= 25) {
            $out[] = ['row' => $r, 'score' => $score];
        }
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['row']['model'], $b['row']['model']));
    return array_slice($out, 0, $limit);
}

/** Free-text search of the roster ("glock 19", "686 plus"). */
function roster_search(PDO $db, string $q, int $limit = 25): array
{
    $words = array_filter(preg_split('/\s+/', strtolower(trim($q))));
    if (!$words) {
        return [];
    }
    $out = [];
    foreach ($db->query('SELECT * FROM ca_roster ORDER BY manufacturer, model') as $r) {
        $hay = roster_compact($r['manufacturer'] . ' ' . roster_make_key($r['manufacturer']) . ' ' . $r['model'] . ' ' . $r['caliber']);
        $ok = true;
        foreach ($words as $w) {
            if (strpos($hay, roster_compact($w)) === false) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $out[] = $r;
            if (count($out) >= $limit) {
                break;
            }
        }
    }
    return $out;
}

// ---------------- copy the roster into firearms ----------------

/**
 * Model name for the Firearms catalog: the roster name without colours / notes in brackets,
 * SKU and catalog numbers, or the maker's name repeated. Falls back to the roster's own name
 * when cleaning would leave nothing (makers that list only a catalog code, e.g. Sig "320CA-9-M18-MS-CA").
 */
function roster_catalog_name(string $modelBase, string $rosterMake, string $displayMake): string
{
    $raw = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $modelBase));   // "(9mm)" added by roster_dedupe
    $s = ' ' . $raw . ' ';
    $s = preg_replace('/\([^)]*\)/', ' ', $s);                          // (Black), (S&W Logo Grip)
    $s = preg_replace('/\bSKU\s*\S+/i', ' ', $s);                       // SKU 164222A
    $s = preg_replace('/\s-\s*\d{4,6}\b|-\d{5}\b/', ' ', $s);           // "- 14295", "-14298"
    $s = preg_replace('/^\s*\d{5}\s+(?=[A-Za-z])/', ' ', $s);           // leading "14420 Bulldog"
    $s = preg_replace('/\s(?:0\d{3,4}|\d{5})(?=\s)/', ' ', $s);         // Ruger catalog numbers "05430", "13747"
    $s = trim(preg_replace('/\s+/', ' ', $s));
    // Maker codes mixed into names ("J92F300CA 92FS", "PX4 Storm Type F JXF9F20", "P226R 226R-40-BSS-CA"):
    // drop them when a real name is left over.
    $words = preg_split('/\s+/', $s);
    $isCode = fn($w) => preg_match('/^(?=[A-Z0-9]*\d)(?=[A-Z0-9]*[A-Z])[A-Z0-9]{6,}$/', $w) || preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+){2,}$/', $w);
    $kept = array_values(array_filter($words, fn($w) => !$isCode($w)));
    if ($kept && preg_match('/[A-Za-z]{2,}|\d/', implode(' ', $kept))) {
        $s = implode(' ', $kept);
    }
    // Colour words and California listing noise ("G19 OD", "Hellcat ... BLK CA OSP"), again only if a name remains.
    $noise = '/\s(?:OD(?:\s+Green)?|FDE|Black|Blk|Gr[ae]y|Two[- ]Tone|Pink|Purple|Tan|Coyote|Bronze|CA|PAC|OSP|OSP-CA|Pistol|UN-WD)(?=\s|$)/i';
    $t = trim(preg_replace('/\s+/', ' ', preg_replace($noise, ' ', ' ' . $s . ' ')));
    if ($t !== '' && preg_match('/[A-Za-z0-9]/', $t)) {
        $s = $t;
    }
    foreach (array_unique([$displayMake, preg_replace('/[,(].*$/', '', $rosterMake)]) as $mk) {
        $mk = trim($mk);
        if ($mk !== '' && stripos($s . ' ', $mk . ' ') === 0 && strlen($s) > strlen($mk) + 1) {
            $s = trim(substr($s, strlen($mk)));                           // "FN Five-seveN" -> "Five-seveN"
        }
    }
    if ($s === '' || !preg_match('/[A-Za-z0-9]/', $s)) {
        $s = trim(preg_replace('/\s+/', ' ', preg_replace('/\([^)]*\)/', ' ', $raw))) ?: $raw;
    }
    if (roster_make_key($displayMake) === 'glock' && preg_match('/^\d/', $s)) {
        $s = 'G' . $s;                                                    // shop naming: "19" -> "G19"
    }
    return $s;
}

/**
 * Add one firearm per make + model + caliber on the roster (colour / grip / SKU variants merged).
 * Skips anything already in Firearms under the same make and model. Safe to run again:
 * only new models are added. Returns counts.
 */
function roster_copy_to_firearms(PDO $db): array
{
    $today = date('Y-m-d');
    $rows = $db->query('SELECT * FROM ca_roster ORDER BY manufacturer, model')->fetchAll();
    $pretty = fn(string $a, string $b) => preg_match_all('/[a-z]/', $b) > preg_match_all('/[a-z]/', $a) ? $b : $a;  // prefer "Super Redhawk" over "SUPER REDHAWK"

    // Group variants.
    $groups = [];
    foreach ($rows as $r) {
        $r['caliber'] = preg_replace('/^(\d+(?:\.\d+)?)MM\b/i', '$1mm', trim((string)$r['caliber']));   // "9MM" -> "9mm"
        $make = roster_display_make($db, $r['manufacturer']);
        $name = roster_catalog_name($r['model_base'], $r['manufacturer'], $make);
        // The caliber is its own field, so drop it from the name ("Hellcat 9mm 3\"" -> "Hellcat 3\"").
        $cal = preg_quote($r['caliber'], '/');
        $t = trim(preg_replace('/\s+/', ' ', preg_replace('/(^|\s)' . $cal . '(?=\s|$)/i', ' ', $name)));
        if ($t !== '' && $cal !== '') {
            $name = $t;
        }
        $key = roster_make_key($make) . '|' . roster_compact($name) . '|' . roster_compact($r['caliber']);
        if (!isset($groups[$key])) {
            $groups[$key] = ['make' => $make, 'name' => $name, 'caliber' => $r['caliber'], 'rows' => []];
        } else {
            $groups[$key]['name'] = $pretty($groups[$key]['name'], $name);
            $groups[$key]['caliber'] = $pretty($groups[$key]['caliber'], $r['caliber']);
        }
        $groups[$key]['rows'][] = $r;
    }
    // Same model in several calibers: one firearm per caliber, caliber in the name.
    $calibers = [];
    foreach ($groups as $g) {
        $calibers[roster_make_key($g['make']) . '|' . roster_compact($g['name'])][roster_compact($g['caliber'])] = true;
    }
    foreach ($groups as &$g) {
        if (count($calibers[roster_make_key($g['make']) . '|' . roster_compact($g['name'])]) > 1) {
            $g['name'] .= ' (' . $g['caliber'] . ')';
        }
    }
    unset($g);

    // What's already in Firearms (same make + model = skip).
    $have = [];
    $slugs = [];
    foreach ($db->query('SELECT manufacturer, model, slug FROM firearms') as $f) {
        $have[roster_make_key($f['manufacturer']) . '|' . roster_compact($f['model'])] = true;
        $slugs[$f['slug']] = true;
    }

    $ins = $db->prepare('INSERT INTO firearms (slug, manufacturer, model, category, caliber, action, barrel_length_in, frame_material,
                             ca_rostered, ca_roster_checked_on, ca_roster_entry, source_url, data_notes)
                         VALUES (?, ?, ?, \'handgun\', ?, ?, ?, ?, 1, ?, ?, ?, ?)');
    $added = $skipped = 0;
    $db->beginTransaction();
    foreach ($groups as $g) {
        $k = roster_make_key($g['make']) . '|' . roster_compact($g['name']);
        if (isset($have[$k])) {
            $skipped++;
            continue;
        }
        $barrels = array_values(array_unique(array_map(fn($r) => $r['barrel_length_in'] === null ? '' : num($r['barrel_length_in']), $g['rows'])));
        $materials = array_values(array_unique(array_map(fn($r) => (string)$r['material'], $g['rows'])));
        $types = array_values(array_unique(array_column($g['rows'], 'gun_type')));
        $first = $g['rows'][0];
        $notes = 'Added from the CA DOJ handgun roster on ' . $today . '. Roster listings: '
            . implode('; ', array_map(fn($r) => $r['model'] . ($r['barrel_length_in'] !== null ? ' (' . num($r['barrel_length_in']) . '")' : ''), $g['rows'])) . '.';
        if (count($barrels) > 1) {
            $notes .= ' Barrel length varies by variant (' . implode(', ', array_map(fn($b) => $b . '"', array_filter($barrels, 'strlen'))) . '), so it is left blank.';
        }
        $slugBase = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($g['make'] . ' ' . $g['name'])), '-');
        $slug = $slugBase;
        for ($n = 2; isset($slugs[$slug]); $n++) {
            $slug = $slugBase . '-' . $n;
        }
        $slugs[$slug] = true;
        $ins->execute([
            $slug, $g['make'], $g['name'], canonical_value('caliber', $g['caliber']),
            $types === ['Revolver'] ? 'Revolver' : null,
            count($barrels) === 1 && $barrels[0] !== '' ? (float)$first['barrel_length_in'] : null,
            count($materials) === 1 && $materials[0] !== '' ? $materials[0] : null,
            $today, $first['detail_path'], ROSTER_SITE . $first['detail_path'], $notes,
        ]);
        $have[$k] = true;
        $added++;
    }
    $db->commit();
    return ['groups' => count($groups), 'added' => $added, 'skipped' => $skipped];
}

function roster_status(PDO $db): array
{
    $r = $db->query('SELECT COUNT(*) n, MAX(imported_at) at FROM ca_roster')->fetch();
    return ['rows' => (int)$r['n'], 'imported_at' => $r['at']];
}

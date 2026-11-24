<?php
declare(strict_types=1);

/*
 * Import a export celého brandu jako jeden ZIP.
 * V kořeni (nebo v jediné složce) archivu je brand.json, soubory jsou na cestách, které v něm uvádí.
 * Formát popisuje docs/IMPORT.md.
 */

const IMPORT_FORMAT = 'brand-hub';

/** Najde brand.json v archivu. Vrací [prefix, manifest]. */
function read_manifest(ZipArchive $zip): array
{
    $prefix = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if ($name === 'brand.json' || preg_match('#^[^/]+/brand\.json$#', $name)) {
            if ($prefix === null || strlen($name) < strlen($prefix . 'brand.json')) {
                $prefix = substr($name, 0, -strlen('brand.json'));
            }
        }
    }
    if ($prefix === null) {
        throw new RuntimeException('V ZIPu chybí soubor brand.json.');
    }
    $json = $zip->getFromName($prefix . 'brand.json');
    $data = json_decode((string) $json, true);
    if (!is_array($data)) {
        throw new RuntimeException('Soubor brand.json není platný JSON: ' . json_last_error_msg());
    }
    if (($data['format'] ?? IMPORT_FORMAT) !== IMPORT_FORMAT) {
        throw new RuntimeException('Soubor brand.json nepatří Brand Hubu.');
    }
    return [$prefix, $data];
}

/** Ověří manifest ještě před založením klienta. Vrací seznam chyb. */
function validate_manifest(array $m, ZipArchive $zip, string $prefix, bool $requireBrand = true): array
{
    $errors = [];
    if ($requireBrand && trim((string) ($m['brand']['name'] ?? '')) === '') {
        $errors[] = 'brand.name: chybí název klienta.';
    }
    foreach ((array) ($m['colors'] ?? []) as $n => $c) {
        if (!normalize_hex((string) ($c['hex'] ?? ''))) {
            $errors[] = 'colors[' . $n . ']: neplatný HEX „' . ($c['hex'] ?? '') . '“.';
        }
    }
    foreach (['texts', 'texts_append'] as $key) {
        foreach ((array) ($m[$key] ?? []) as $sec => $_) {
            if (!isset(SECTIONS[$sec]) && $sec !== 'digital') {
                $errors[] = $key . ': neznámá sekce „' . $sec . '“.';
            }
        }
    }
    foreach ((array) ($m['items'] ?? []) as $n => $it) {
        $label = 'items[' . $n . '] (' . ($it['title'] ?? '?') . ')';
        if ((!isset(SECTIONS[$it['section'] ?? '']) && ($it['section'] ?? '') !== 'digital') || ($it['section'] ?? '') === 'barvy') {
            $errors[] = $label . ': neznámá sekce „' . ($it['section'] ?? '') . '“.';
        }
        if (trim((string) ($it['title'] ?? '')) === '' && trim((string) ($it['slug'] ?? '')) === '') {
            $errors[] = $label . ': chybí název.';
        }
        if (isset($it['mode']) && !in_array($it['mode'], ['new', 'append'], true)) {
            $errors[] = $label . ': mode může být jen „new“ (nová verze) nebo „append“ (přidat k aktuální verzi).';
        }
        foreach ((array) ($it['files'] ?? []) as $f) {
            $path = (string) ($f['path'] ?? '');
            if ($path === '' || str_contains($path, '..') || $zip->locateName($prefix . $path) === false) {
                $errors[] = $label . ': soubor „' . $path . '“ v ZIPu není.';
            } elseif (!in_array(file_ext($path), ALLOWED_EXT, true)) {
                $errors[] = $label . ': typ souboru „' . $path . '“ nelze nahrát.';
            }
        }
    }
    return $errors;
}

/**
 * Balíčky připravené do verze 1.4 mají jednu sekci „digital“. Pozice rozdělí podle slugu,
 * text podle nadpisů (stejně jako migrace 007 u stávajících klientů).
 */
function upgrade_legacy_manifest(array $m): array
{
    foreach ((array) ($m['items'] ?? []) as $n => $it) {
        if (($it['section'] ?? '') === 'digital') {
            $m['items'][$n]['section'] = legacy_digital_section(slugify(trim((string) ($it['slug'] ?? '')) ?: (string) ($it['title'] ?? '')));
        }
    }
    foreach (['texts', 'texts_append'] as $key) {
        if (isset($m[$key]['digital'])) {
            $old = (string) $m[$key]['digital'];
            unset($m[$key]['digital']);
            foreach (split_legacy_digital_text($old) as $sec => $part) {
                $m[$key][$sec] = trim((string) ($m[$key][$sec] ?? '')) === '' ? $part : rtrim((string) $m[$key][$sec]) . "\n\n" . $part;
            }
        }
    }
    return $m;
}

/**
 * Založí nového klienta z importního ZIPu.
 * Vrací ['brand_id' => int, 'password' => string, 'stats' => [...]].
 */
function import_brand_package(string $zipFile): array
{
    @set_time_limit(600);
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('Soubor není platný ZIP.');
    }
    [$prefix, $m] = read_manifest($zip);
    $errors = validate_manifest($m, $zip, $prefix);
    $m = upgrade_legacy_manifest($m);
    if ($errors) {
        $zip->close();
        throw new RuntimeException("Import se nespustil, v balíčku jsou chyby:\n– " . implode("\n– ", $errors));
    }

    $b = $m['brand'];
    $password = trim((string) ($b['password'] ?? '')) ?: suggest_password();
    $hero = normalize_hex((string) ($b['hero_bg'] ?? '')) ?? '#EAF2FA';
    $brandId = db_insert(
        'INSERT INTO brands (name, slug, intro, contact_name, contact_note, contact_phone, contact_email, hero_bg, password_hash, share_enabled, share_token, share_expires_at, public_links, brand_fonts, custom_domain, is_active, sort, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NULL, ?, ?, NULL, 1, ' . brand_sort_top() . ', NOW(), NOW())',
        [
            trim((string) $b['name']),
            unique_brand_slug(trim((string) ($b['slug'] ?? '')) ?: (string) $b['name']),
            trim((string) ($b['intro'] ?? '')) ?: null,
            trim((string) ($b['contact_name'] ?? '')) ?: null,
            trim((string) ($b['contact_note'] ?? '')) ?: null,
            array_key_exists('contact_phone', $b) ? (mb_substr(trim((string) $b['contact_phone']), 0, 40) ?: null) : DEFAULT_CONTACT_PHONE,
            array_key_exists('contact_email', $b) ? (filter_var(trim((string) $b['contact_email']), FILTER_VALIDATE_EMAIL) ?: null) : DEFAULT_CONTACT_EMAIL,
            $hero,
            password_hash($password, PASSWORD_DEFAULT),
            new_share_token(),
            array_key_exists('public_links', $b) ? (!empty($b['public_links']) ? 1 : 0) : 1,
            array_key_exists('brand_fonts', $b) ? (!empty($b['brand_fonts']) ? 1 : 0) : 1,
        ]
    );

    $stats = ['colors' => 0, 'texts' => 0, 'items' => 0, 'files' => 0, 'pruned' => 0, 'webp' => 0];
    $webp = webp_supported();
    try {
        sync_template($brandId);

        foreach (array_values((array) ($m['colors'] ?? [])) as $n => $c) {
            db_exec(
                'INSERT INTO colors (brand_id, name, hex, cmyk, pantone, role, usage_note, tints, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $brandId, mb_substr(trim((string) ($c['name'] ?? '')) ?: (string) normalize_hex((string) $c['hex']), 0, 80), normalize_hex((string) $c['hex']),
                    mb_substr(trim((string) ($c['cmyk'] ?? '')), 0, 40) ?: null, mb_substr(trim((string) ($c['pantone'] ?? '')), 0, 40) ?: null,
                    mb_substr(trim((string) ($c['role'] ?? '')), 0, 60) ?: null, mb_substr(trim((string) ($c['usage'] ?? '')), 0, 255) ?: null,
                    !empty($c['tints']) ? 1 : 0, ($n + 1) * 10,
                ]
            );
            $stats['colors']++;
        }

        foreach ((array) ($m['texts'] ?? []) as $sec => $body) {
            save_section_text($brandId, (string) $sec, (string) $body);
            $stats['texts']++;
        }

        $touched = [];
        $sortBySection = [];
        foreach ((array) ($m['items'] ?? []) as $it) {
            $section = (string) $it['section'];
            $title = trim((string) ($it['title'] ?? ''));
            $slug = slugify(trim((string) ($it['slug'] ?? '')) ?: $title);
            $sortBySection[$section] = ($sortBySection[$section] ?? 0) + 10;
            $existing = db_one('SELECT * FROM items WHERE brand_id = ? AND slug = ?', [$brandId, $slug]);
            $fields = [
                'section' => $section,
                'kind' => ($it['kind'] ?? ($section === 'typografie' ? 'font' : 'file')) === 'font' ? 'font' : 'file',
                'title' => $title ?: ($existing['title'] ?? $slug),
                'description' => array_key_exists('description', $it) ? (trim((string) $it['description']) ?: null) : ($existing['description'] ?? null),
                'specs' => array_key_exists('specs', $it) ? (trim((string) $it['specs']) ?: null) : ($existing['specs'] ?? null),
                'tags' => array_key_exists('tags', $it) ? (trim((string) $it['tags']) ?: null) : ($existing['tags'] ?? null),
                'required' => array_key_exists('required', $it) ? (!empty($it['required']) ? 1 : 0) : (int) ($existing['required'] ?? 0),
                'preview_bg' => array_key_exists('preview_bg', $it) ? normalize_hex((string) $it['preview_bg']) : ($existing['preview_bg'] ?? null),
                'meta' => json_encode(is_array($it['meta'] ?? null) ? $it['meta'] : [], JSON_UNESCAPED_UNICODE),
                'sort' => $sortBySection[$section],
            ];
            if ($existing) {
                $itemId = (int) $existing['id'];
                db_exec(
                    'UPDATE items SET section = ?, kind = ?, title = ?, description = ?, specs = ?, tags = ?, required = ?, preview_bg = ?, meta = ?, sort = ?, updated_at = NOW() WHERE id = ?',
                    [...array_values($fields), $itemId]
                );
            } else {
                $itemId = db_insert(
                    'INSERT INTO items (brand_id, section, kind, title, slug, description, specs, tags, required, preview_bg, meta, sort, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [$brandId, $fields['section'], $fields['kind'], $fields['title'], unique_item_slug($brandId, $slug), $fields['description'], $fields['specs'], $fields['tags'], $fields['required'], $fields['preview_bg'], $fields['meta'], $fields['sort']]
                );
            }
            $touched[] = $itemId;
            $stats['items']++;

            $uploads = [];
            foreach ((array) ($it['files'] ?? []) as $f) {
                $uploadId = bin2hex(random_bytes(16));
                ensure_dir(data_path('tmp/chunks'));
                $in = $zip->getStream($prefix . $f['path']);
                $out = fopen(chunk_path($uploadId), 'wb');
                if (!$in || !$out) {
                    throw new RuntimeException('Soubor ' . $f['path'] . ' nelze rozbalit.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
                $uploads[] = [
                    'upload_id' => $uploadId,
                    'name' => basename((string) $f['path']),
                    'label' => (string) ($f['label'] ?? ''),
                    'role' => ($f['role'] ?? 'download') === 'preview' ? 'preview' : 'download',
                    'is_preview' => !empty($f['preview']),
                ];
            }
            if ($uploads) {
                $item = item_by_id($itemId);
                commit_uploads($item, $uploads, 'new', trim((string) ($it['note'] ?? '')) ?: 'Import');
                $stats['files'] += count($uploads);
                // Chybějící WebP k PNG/JPG dopočítá server (balíček by je měl mít, viz docs/IMPORT-PRAVIDLA.md).
                if ($webp && in_array($section, WEBP_SECTIONS, true)) {
                    $stats['webp'] += item_add_webp(item_by_id($itemId))['added'];
                }
            }
        }

        // Prázdné pozice ze šablony, které import nepoužil, lze rovnou odebrat (vrátí je „Doplnit ze šablony“).
        if (!empty($m['prune_empty'])) {
            $keep = $touched ?: [0];
            $in = implode(',', array_map('intval', $keep));
            $stats['pruned'] = db_exec("DELETE FROM items WHERE brand_id = ? AND current_version_id IS NULL AND id NOT IN ($in)", [$brandId]);
        }
    } catch (Throwable $e) {
        // Nedokončený import po sobě uklidí.
        rrmdir(data_path('files/' . $brandId));
        db_exec('DELETE FROM brands WHERE id = ?', [$brandId]);
        $zip->close();
        throw $e;
    }
    $zip->close();
    touch_brand($brandId);
    log_activity($brandId, 'admin', 'import', sprintf('Import: %d barev, %d textů, %d pozic, %d souborů', $stats['colors'], $stats['texts'], $stats['items'], $stats['files']) . ($stats['webp'] ? ', ' . $stats['webp'] . ' WebP doplněno' : ''));
    return ['brand_id' => $brandId, 'password' => $password, 'stats' => $stats];
}

/* ---------- Doplnění existujícího klienta (od 1.6.0) ---------- */

/** Rozbalí soubor z importního ZIPu do dočasné složky nahrávání. Vrací upload_id. */
function zip_entry_to_upload(ZipArchive $zip, string $entry): string
{
    $uploadId = bin2hex(random_bytes(16));
    ensure_dir(data_path('tmp/chunks'));
    $in = $zip->getStream($entry);
    $out = fopen(chunk_path($uploadId), 'wb');
    if (!$in || !$out) {
        throw new RuntimeException('Soubor ' . $entry . ' nelze rozbalit.');
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    return $uploadId;
}

/** Klíč pozice v balíčku: slug, jinak název. */
function package_item_slug(array $it): string
{
    return slugify(trim((string) ($it['slug'] ?? '')) ?: (string) ($it['title'] ?? ''));
}

/**
 * Rozbor doplňkového balíčku pro existujícího klienta. Nic nemění.
 * Vrací ['items' => [...], 'colors' => [...], 'texts' => [...], 'warnings' => [...], 'errors' => [...]].
 */
function supplement_plan(array $brand, ZipArchive $zip, string $prefix, array $m): array
{
    $brandId = (int) $brand['id'];
    $plan = ['items' => [], 'colors' => [], 'texts' => [], 'warnings' => [], 'errors' => []];

    $plan['errors'] = validate_manifest($m, $zip, $prefix, false);
    $m = upgrade_legacy_manifest($m);

    $pkgSlug = trim((string) ($m['brand']['slug'] ?? ''));
    if ($pkgSlug !== '' && slugify($pkgSlug) !== $brand['slug']) {
        $plan['warnings'][] = 'Balíček je připravený pro klienta „' . trim((string) ($m['brand']['name'] ?? $pkgSlug)) . '“ (' . $pkgSlug . '), doplňujete ho ale do klienta „' . $brand['name'] . '“. Zkontrolujte, že jste vybrali správného klienta.';
    }
    if (!empty($m['prune_empty'])) {
        $plan['warnings'][] = 'Volbu prune_empty doplnění ignoruje, žádné pozice se nemažou.';
    }

    $seen = [];
    foreach ((array) ($m['items'] ?? []) as $n => $it) {
        $slug = package_item_slug($it);
        if ($slug === '') {
            continue;
        }
        if (isset($seen[$slug])) {
            $plan['errors'][] = 'items[' . $n . '] (' . ($it['title'] ?? $slug) . '): pozice „' . $slug . '“ je v balíčku dvakrát, spojte soubory do jedné pozice.';
            continue;
        }
        $seen[$slug] = true;
        $existing = db_one('SELECT * FROM items WHERE brand_id = ? AND slug = ?', [$brandId, $slug]);
        $files = count((array) ($it['files'] ?? []));
        $mode = ($it['mode'] ?? 'new') === 'append' ? 'append' : 'new';
        if (!$existing) {
            $action = 'create';
        } elseif (!$files) {
            $action = 'meta';
        } elseif (!$existing['current_version_id']) {
            $action = 'fill';
        } else {
            $action = $mode === 'append' ? 'append' : 'version';
        }
        $plan['items'][] = [
            'action' => $action,
            'section' => (string) ($it['section'] ?? ''),
            'from_section' => $existing && $existing['section'] !== ($it['section'] ?? '') ? $existing['section'] : null,
            'slug' => $slug,
            'title' => trim((string) ($it['title'] ?? '')) ?: ($existing['title'] ?? $slug),
            'files' => $files,
        ];
    }

    $current = brand_colors($brandId);
    foreach ((array) ($m['colors'] ?? []) as $c) {
        $match = supplement_color_match($current, $c);
        $plan['colors'][] = [
            'action' => $match ? 'update' : 'create',
            'name' => trim((string) ($c['name'] ?? '')) ?: (string) normalize_hex((string) ($c['hex'] ?? '')),
            'hex' => normalize_hex((string) ($c['hex'] ?? '')),
            'old_hex' => $match && normalize_hex((string) ($c['hex'] ?? '')) !== $match['hex'] ? $match['hex'] : null,
        ];
    }

    $texts = section_texts($brandId);
    foreach ((array) ($m['texts'] ?? []) as $sec => $body) {
        $plan['texts'][] = ['section' => (string) $sec, 'action' => trim((string) $body) === '' ? 'delete' : (isset($texts[$sec]) ? 'replace' : 'create')];
    }
    foreach ((array) ($m['texts_append'] ?? []) as $sec => $body) {
        if (trim((string) $body) !== '') {
            $plan['texts'][] = ['section' => (string) $sec, 'action' => isset($texts[$sec]) ? 'append' : 'create'];
        }
    }

    if (!$plan['items'] && !$plan['colors'] && !$plan['texts'] && !$plan['errors']) {
        $plan['errors'][] = 'Balíček nic nedoplňuje: chybí items, colors i texts.';
    }
    return $plan;
}

/** Najde stávající barvu podle názvu, jinak podle HEX. */
function supplement_color_match(array $current, array $c): ?array
{
    $name = mb_strtolower(trim((string) ($c['name'] ?? '')));
    if ($name !== '') {
        foreach ($current as $row) {
            if (mb_strtolower(trim((string) $row['name'])) === $name) {
                return $row;
            }
        }
    }
    $hex = normalize_hex((string) ($c['hex'] ?? ''));
    foreach ($current as $row) {
        if ($hex && strtoupper((string) $row['hex']) === strtoupper($hex)) {
            return $row;
        }
    }
    return null;
}

/** Otevře doplňkový balíček a vrátí [zip, prefix, manifest, plán]. */
function open_supplement(array $brand, string $zipFile): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('Soubor není platný ZIP.');
    }
    try {
        [$prefix, $m] = read_manifest($zip);
    } catch (Throwable $e) {
        $zip->close();
        throw $e;
    }
    return [$zip, $prefix, $m, supplement_plan($brand, $zip, $prefix, $m)];
}

/**
 * Doplní do existujícího klienta pozice, soubory, barvy a texty z balíčku (stejný formát jako import).
 * Nic nemaže: pozice se stejným slugem dostane novou verzi (stará jde do historie), nebo se soubory přidají
 * k aktuální verzi („mode": "append"). Při chybě se vše vrátí do původního stavu.
 */
function supplement_brand_package(array $brand, string $zipFile): array
{
    @set_time_limit(600);
    $brandId = (int) $brand['id'];
    [$zip, $prefix, $m, $plan] = open_supplement($brand, $zipFile);
    if ($plan['errors']) {
        $zip->close();
        throw new RuntimeException("Doplnění se nespustilo, v balíčku jsou chyby:\n– " . implode("\n– ", $plan['errors']));
    }
    $m = upgrade_legacy_manifest($m);

    $stats = ['created' => 0, 'versions' => 0, 'filled' => 0, 'appended' => 0, 'meta' => 0, 'files' => 0, 'colors' => 0, 'texts' => 0, 'webp' => 0, 'sections' => [], 'item_sections' => []];
    $webp = webp_supported();
    $dirs = [];      // [složka, soubory před změnou | null = nová složka] kvůli úklidu při chybě
    $uploads = [];

    db()->beginTransaction();
    try {
        // Barvy: stejný název (jinak HEX) = úprava, jinak nová barva na konec palety.
        foreach ((array) ($m['colors'] ?? []) as $c) {
            $hex = normalize_hex((string) $c['hex']);
            $match = supplement_color_match(brand_colors($brandId), $c);
            $val = fn (string $k, int $len, ?string $old) => array_key_exists($k, $c) ? (mb_substr(trim((string) $c[$k]), 0, $len) ?: null) : $old;
            if ($match) {
                db_exec(
                    'UPDATE colors SET name = ?, hex = ?, cmyk = ?, pantone = ?, role = ?, usage_note = ?, tints = ? WHERE id = ?',
                    [
                        mb_substr(trim((string) ($c['name'] ?? '')) ?: (string) $match['name'], 0, 80), $hex,
                        $val('cmyk', 40, $match['cmyk']), $val('pantone', 40, $match['pantone']), $val('role', 60, $match['role']),
                        $val('usage', 255, $match['usage_note']),
                        array_key_exists('tints', $c) ? (!empty($c['tints']) ? 1 : 0) : (int) $match['tints'],
                        $match['id'],
                    ]
                );
            } else {
                $sort = (int) db_val('SELECT COALESCE(MAX(sort), 0) + 10 FROM colors WHERE brand_id = ?', [$brandId]);
                db_exec(
                    'INSERT INTO colors (brand_id, name, hex, cmyk, pantone, role, usage_note, tints, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$brandId, mb_substr(trim((string) ($c['name'] ?? '')) ?: $hex, 0, 80), $hex, $val('cmyk', 40, null), $val('pantone', 40, null), $val('role', 60, null), $val('usage', 255, null), !empty($c['tints']) ? 1 : 0, $sort]
                );
            }
            $stats['colors']++;
            $stats['sections']['barvy'] = true;
        }

        // Texty: „texts“ nahradí text sekce, „texts_append“ ho doplní na konec.
        $texts = section_texts($brandId);
        foreach ((array) ($m['texts'] ?? []) as $sec => $body) {
            save_section_text($brandId, (string) $sec, (string) $body);
            $texts[$sec] = (string) $body;
            $stats['texts']++;
            $stats['sections'][$sec] = true;
        }
        foreach ((array) ($m['texts_append'] ?? []) as $sec => $body) {
            if (trim((string) $body) === '') {
                continue;
            }
            $old = trim((string) ($texts[$sec] ?? ''));
            save_section_text($brandId, (string) $sec, $old === '' ? (string) $body : $old . "\n\n" . trim((string) $body));
            $stats['texts']++;
            $stats['sections'][$sec] = true;
        }

        // Pozice
        foreach ((array) ($m['items'] ?? []) as $it) {
            $section = (string) $it['section'];
            $slug = package_item_slug($it);
            $title = trim((string) ($it['title'] ?? ''));
            $existing = db_one('SELECT * FROM items WHERE brand_id = ? AND slug = ?', [$brandId, $slug]);
            $endSort = (int) db_val('SELECT COALESCE(MAX(sort), 0) + 10 FROM items WHERE brand_id = ? AND section = ?', [$brandId, $section]);
            $kindOf = fn (?array $ex) => array_key_exists('kind', $it)
                ? ($it['kind'] === 'font' ? 'font' : 'file')
                : ($ex && $ex['section'] === $section ? $ex['kind'] : ($section === 'typografie' ? 'font' : 'file'));

            if ($existing) {
                $itemId = (int) $existing['id'];
                $keep = fn (string $k) => array_key_exists($k, $it) ? (trim((string) $it[$k]) ?: null) : $existing[$k];
                db_exec(
                    'UPDATE items SET section = ?, kind = ?, title = ?, description = ?, specs = ?, tags = ?, required = ?, preview_bg = ?, meta = ?, sort = ?, updated_at = NOW() WHERE id = ?',
                    [
                        $section, $kindOf($existing), $title ?: $existing['title'], $keep('description'), $keep('specs'), $keep('tags'),
                        array_key_exists('required', $it) ? (!empty($it['required']) ? 1 : 0) : (int) $existing['required'],
                        array_key_exists('preview_bg', $it) ? normalize_hex((string) $it['preview_bg']) : $existing['preview_bg'],
                        is_array($it['meta'] ?? null) ? json_encode($it['meta'], JSON_UNESCAPED_UNICODE) : $existing['meta'],
                        $existing['section'] === $section ? (int) $existing['sort'] : $endSort,
                        $itemId,
                    ]
                );
            } else {
                $itemId = db_insert(
                    'INSERT INTO items (brand_id, section, kind, title, slug, description, specs, tags, required, preview_bg, meta, sort, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [
                        $brandId, $section, $kindOf(null), $title ?: $slug, unique_item_slug($brandId, $slug),
                        trim((string) ($it['description'] ?? '')) ?: null, trim((string) ($it['specs'] ?? '')) ?: null, trim((string) ($it['tags'] ?? '')) ?: null,
                        !empty($it['required']) ? 1 : 0, normalize_hex((string) ($it['preview_bg'] ?? '')),
                        json_encode(is_array($it['meta'] ?? null) ? $it['meta'] : [], JSON_UNESCAPED_UNICODE), $endSort,
                    ]
                );
                $stats['created']++;
            }
            $stats['sections'][$section] = true;
            $stats['item_sections'][$section] = true;

            $files = (array) ($it['files'] ?? []);
            if (!$files) {
                if ($existing) {
                    $stats['meta']++;
                }
                continue;
            }
            $batch = [];
            foreach ($files as $f) {
                $id = zip_entry_to_upload($zip, $prefix . $f['path']);
                $uploads[] = $id;
                $batch[] = [
                    'upload_id' => $id,
                    'name' => basename((string) $f['path']),
                    'label' => (string) ($f['label'] ?? ''),
                    'role' => ($f['role'] ?? 'download') === 'preview' ? 'preview' : 'download',
                    'is_preview' => !empty($f['preview']),
                ];
            }
            $item = item_by_id($itemId);
            $mode = ($it['mode'] ?? 'new') === 'append' && $item['current_version_id'] ? 'append' : 'new';
            if ($mode === 'append') {
                $dir = version_dir($brandId, $itemId, (int) $item['current_version_id']);
                $dirs[] = [$dir, is_dir($dir) ? scandir($dir) : null];
            }
            $versionId = commit_uploads($item, $batch, $mode, trim((string) ($it['note'] ?? '')) ?: 'Doplnění');
            if ($mode === 'new') {
                $dirs[] = [version_dir($brandId, $itemId, $versionId), null];
                if ($existing && $existing['current_version_id']) {
                    $stats['versions']++;
                } elseif ($existing) {
                    $stats['filled']++;
                }
            } else {
                $stats['appended']++;
            }
            $stats['files'] += count($batch);
            if ($webp && in_array($section, WEBP_SECTIONS, true)) {
                $stats['webp'] += item_add_webp(item_by_id($itemId))['added'];
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        // Soubory, které stihly vzniknout, zase odstraním.
        foreach (array_reverse($dirs) as [$dir, $before]) {
            if ($before === null) {
                rrmdir($dir);
            } elseif (is_dir($dir)) {
                foreach (array_diff(scandir($dir), $before) as $f) {
                    @unlink($dir . '/' . $f);
                }
            }
        }
        foreach ($uploads as $id) {
            @unlink(chunk_path($id));
        }
        $zip->close();
        throw $e;
    }
    $zip->close();
    touch_brand($brandId);
    log_activity($brandId, 'admin', 'import', sprintf(
        'Doplnění z balíčku: %d nových pozic, %d vyplněných, %d nových verzí, %d doplněných verzí, %d souborů, %d barev, %d textů',
        $stats['created'], $stats['filled'], $stats['versions'], $stats['appended'], $stats['files'], $stats['colors'], $stats['texts']
    ) . ($stats['webp'] ? ', ' . $stats['webp'] . ' WebP doplněno' : ''));
    $stats['sections'] = array_keys($stats['sections']);
    $stats['item_sections'] = array_keys($stats['item_sections']);
    return $stats;
}

/** Export brandu do stejného formátu (záloha, přenos na jiný server). */
function send_brand_export(array $brand): never
{
    @set_time_limit(0);
    $brandId = (int) $brand['id'];
    $manifest = [
        'format' => IMPORT_FORMAT,
        'version' => 1,
        'exported_at' => date('c'),
        'app_version' => APP_VERSION,
        'brand' => [
            'name' => $brand['name'],
            'slug' => $brand['slug'],
            'intro' => $brand['intro'],
            'hero_bg' => $brand['hero_bg'],
            'contact_name' => $brand['contact_name'],
            'contact_note' => $brand['contact_note'],
            'contact_phone' => $brand['contact_phone'] ?? null,
            'contact_email' => $brand['contact_email'] ?? null,
            'public_links' => (bool) $brand['public_links'],
            'brand_fonts' => (bool) ($brand['brand_fonts'] ?? true),
        ],
        'texts' => section_texts($brandId),
        'colors' => array_map(fn ($c) => [
            'name' => $c['name'], 'hex' => $c['hex'], 'cmyk' => $c['cmyk'], 'pantone' => $c['pantone'],
            'role' => $c['role'], 'usage' => $c['usage_note'], 'tints' => (bool) $c['tints'],
        ], brand_colors($brandId)),
        'items' => [],
        'prune_empty' => true,
    ];
    ensure_dir(data_path('tmp'));
    $tmp = data_path('tmp/export-' . bin2hex(random_bytes(6)) . '.zip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (brand_items($brandId) as $it) {
        $entry = [
            'section' => $it['section'], 'slug' => $it['slug'], 'title' => $it['title'], 'kind' => $it['kind'],
            'description' => $it['description'], 'specs' => $it['specs'], 'tags' => $it['tags'],
            'required' => (bool) $it['required'], 'preview_bg' => $it['preview_bg'], 'meta' => $it['meta'] ?: new stdClass(),
            'files' => [],
        ];
        foreach ($it['all_files'] as $f) {
            $path = 'files/' . $it['section'] . '/' . $it['slug'] . '/' . $f['stored_name'];
            $abs = stored_file_path($f);
            if (is_file($abs)) {
                $zip->addFile($abs, $path);
                $zip->setCompressionName($path, ZipArchive::CM_STORE);
                $entry['files'][] = array_filter([
                    'path' => $path, 'label' => $f['label'],
                    'role' => $f['role'] === 'preview' ? 'preview' : null,
                    'preview' => !empty($f['is_preview']) ?: null,
                ], fn ($v) => $v !== null);
            }
        }
        $manifest['items'][] = $entry;
    }
    $zip->addFromString('brand.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $zip->close();
    register_shutdown_function(fn () => @unlink($tmp));
    stream_file($tmp, $brand['slug'] . '-export-' . date('Y-m-d') . '.zip', false, '', 0);
}

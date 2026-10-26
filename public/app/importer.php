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
function validate_manifest(array $m, ZipArchive $zip, string $prefix): array
{
    $errors = [];
    if (trim((string) ($m['brand']['name'] ?? '')) === '') {
        $errors[] = 'brand.name: chybí název klienta.';
    }
    foreach ((array) ($m['colors'] ?? []) as $n => $c) {
        if (!normalize_hex((string) ($c['hex'] ?? ''))) {
            $errors[] = 'colors[' . $n . ']: neplatný HEX „' . ($c['hex'] ?? '') . '“.';
        }
    }
    foreach ((array) ($m['texts'] ?? []) as $sec => $_) {
        if (!isset(SECTIONS[$sec])) {
            $errors[] = 'texts: neznámá sekce „' . $sec . '“.';
        }
    }
    foreach ((array) ($m['items'] ?? []) as $n => $it) {
        $label = 'items[' . $n . '] (' . ($it['title'] ?? '?') . ')';
        if (!isset(SECTIONS[$it['section'] ?? '']) || ($it['section'] ?? '') === 'barvy') {
            $errors[] = $label . ': neznámá sekce „' . ($it['section'] ?? '') . '“.';
        }
        if (trim((string) ($it['title'] ?? '')) === '' && trim((string) ($it['slug'] ?? '')) === '') {
            $errors[] = $label . ': chybí název.';
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
    if ($errors) {
        $zip->close();
        throw new RuntimeException("Import se nespustil, v balíčku jsou chyby:\n– " . implode("\n– ", $errors));
    }

    $b = $m['brand'];
    $password = trim((string) ($b['password'] ?? '')) ?: suggest_password();
    $hero = normalize_hex((string) ($b['hero_bg'] ?? '')) ?? '#EAF2FA';
    $brandId = db_insert(
        'INSERT INTO brands (name, slug, intro, contact_name, contact_note, contact_phone, contact_email, hero_bg, password_hash, share_enabled, share_token, share_expires_at, public_links, brand_fonts, custom_domain, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NULL, ?, ?, NULL, 1, NOW(), NOW())',
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

    $stats = ['colors' => 0, 'texts' => 0, 'items' => 0, 'files' => 0, 'pruned' => 0];
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
    log_activity($brandId, 'admin', 'import', sprintf('Import: %d barev, %d textů, %d pozic, %d souborů', $stats['colors'], $stats['texts'], $stats['items'], $stats['files']));
    return ['brand_id' => $brandId, 'password' => $password, 'stats' => $stats];
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

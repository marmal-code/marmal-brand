<?php
declare(strict_types=1);

/* ================= Klientský pohled ================= */

function load_client_brand(string $slug): array
{
    $brand = brand_by_slug($slug);
    if (!$brand || (!$brand['is_active'] && !current_admin())) {
        abort(404, 'Tento brand manuál neexistuje nebo už není aktivní.');
    }
    return $brand;
}

/** Zajistí přístup; jinak zobrazí přihlášení. Vrací typ přístupu. */
function client_guard(array $brand): string
{
    $access = brand_access($brand);
    if ($access) {
        return $access;
    }
    render('client/login', ['brand' => $brand, 'error' => null, 'title' => $brand['name']], 'client_bare');
}

function client_home(string $slug): void
{
    $brand = load_client_brand($slug);

    // Sdílený odkaz ?s=TOKEN
    if (isset($_GET['s'])) {
        $token = (string) $_GET['s'];
        if (share_link_valid($brand) && hash_equals((string) $brand['share_token'], $token)) {
            $_SESSION['share'][(int) $brand['id']] = $token;
            log_activity((int) $brand['id'], 'share', 'share', 'Otevřen sdílený odkaz');
            redirect(brand_url($brand));
        }
        render('client/login', ['brand' => $brand, 'error' => 'Sdílený odkaz už neplatí. Požádejte o nový, nebo se přihlaste heslem.', 'title' => $brand['name']], 'client_bare');
    }

    $access = client_guard($brand);
    $items = brand_items((int) $brand['id'], null, true);
    $bySection = [];
    foreach ($items as $it) {
        $bySection[$it['section']][] = $it;
    }
    render('client/home', client_vars($brand, $access) + [
        'title' => $brand['name'] . ' – brand manuál',
        'current' => 'home',
        'bySection' => $bySection,
        'colors' => brand_colors((int) $brand['id']),
    ], 'client');
}

function client_vars(array $brand, string $access): array
{
    $counts = [];
    foreach (db_all('SELECT section, COUNT(*) AS n FROM items WHERE brand_id = ? AND current_version_id IS NOT NULL GROUP BY section', [$brand['id']]) as $r) {
        $counts[$r['section']] = (int) $r['n'];
    }
    $counts['barvy'] = (int) db_val('SELECT COUNT(*) FROM colors WHERE brand_id = ?', [$brand['id']]);
    $totals = db_one(
        'SELECT COUNT(*) AS n, COALESCE(SUM(f.size), 0) AS size FROM item_files f JOIN items i ON i.current_version_id = f.version_id WHERE i.brand_id = ? AND f.role = ?',
        [$brand['id'], 'download']
    );
    $texts = section_texts((int) $brand['id']);
    // Nahraný PDF manuál: první PDF v sekci Pravidla použití
    $manualPdf = null;
    foreach (brand_items((int) $brand['id'], 'pravidla', true) as $it) {
        foreach ($it['files'] as $f) {
            if (file_ext($f['stored_name']) === 'pdf') {
                $manualPdf = $f;
                break 2;
            }
        }
    }
    return [
        'manualPdf' => $manualPdf,
        'brand' => $brand,
        'access' => $access,
        'counts' => $counts,
        'texts' => $texts,
        'totals' => $totals,
        'symbol' => brand_symbol((int) $brand['id']),
        'typefaces' => brand_typefaces($brand),
        'admin' => current_admin(),
    ];
}

function client_login(string $slug): void
{
    $brand = load_client_brand($slug);
    $error = brand_login($brand, (string) ($_POST['password'] ?? ''));
    if ($error) {
        render('client/login', ['brand' => $brand, 'error' => $error, 'title' => $brand['name']], 'client_bare');
    }
    redirect(brand_url($brand));
}

function client_logout(string $slug): void
{
    $brand = load_client_brand($slug);
    unset($_SESSION['brands'][(int) $brand['id']], $_SESSION['share'][(int) $brand['id']]);
    redirect(brand_url($brand));
}

function client_section(string $slug, string $section): void
{
    $brand = load_client_brand($slug);
    if (!isset(SECTIONS[$section])) {
        abort(404);
    }
    $access = client_guard($brand);
    render('client/section', client_vars($brand, $access) + [
        'title' => SECTIONS[$section]['title'] . ' – ' . $brand['name'],
        'current' => $section,
        'section' => $section,
        'items' => $section === 'barvy' ? [] : brand_items((int) $brand['id'], $section, true),
        'colors' => $section === 'barvy' ? brand_colors((int) $brand['id']) : [],
    ], 'client');
}

/** Stažení / náhled konkrétního souboru – jen soubory aktuálních verzí. */
function client_file(string $slug, string $fileId): void
{
    $brand = load_client_brand($slug);
    $access = brand_access($brand);
    if (!$access) {
        abort(403, 'Pro stažení se nejdřív přihlaste.');
    }
    $row = db_one(
        'SELECT f.*, i.id AS item_id, i.slug AS item_slug, i.title AS item_title
         FROM item_files f JOIN items i ON i.current_version_id = f.version_id
         WHERE f.id = ? AND i.brand_id = ?',
        [(int) $fileId, $brand['id']]
    );
    if (!$row) {
        abort(404, 'Soubor už není aktuální. Načtěte stránku znovu.');
    }
    $inline = !empty($_GET['inline']);
    if (!$inline && $access !== 'admin') {
        log_activity((int) $brand['id'], $access, 'download', $row['item_title'] . ' – ' . $row['label']);
    }
    $item = ['slug' => $row['item_slug']];
    stream_file(stored_file_path($row), $inline ? $row['stored_name'] : download_name($brand, $item, $row), $inline, (string) $row['mime'], $inline ? 31536000 : 300);
}

/** Trvalý odkaz /b/{slug}/f/{pozice}.{přípona} – vždy vrací aktuální verzi. */
function client_permalink(string $slug, string $itemSlug, string $ext): void
{
    $brand = load_client_brand($slug);
    $access = brand_access($brand);
    if (!$access && !$brand['public_links']) {
        abort(403, 'Trvalé odkazy tohoto brandu nejsou veřejné.');
    }
    $item = db_one('SELECT * FROM items WHERE brand_id = ? AND slug = ? AND current_version_id IS NOT NULL', [$brand['id'], $itemSlug]);
    if (!$item) {
        abort(404);
    }
    $item = attach_files([$item])[0];
    $file = null;
    $exts = $ext === 'jpg' ? ['jpg', 'jpeg'] : [$ext];
    foreach ($item['all_files'] as $f) {
        if (in_array(file_ext($f['stored_name']), $exts, true) && ($file === null || $f['role'] === 'download')) {
            $file = $f;
            if ($f['role'] === 'download') {
                break;
            }
        }
    }
    if (!$file) {
        abort(404, 'Tento formát u položky není.');
    }
    if (!$access || $access !== 'admin') {
        // Obrázky vložené do webu nebo podpisu by zahltily log – logujeme jen stažení.
        if (!in_array($ext, IMAGE_EXT, true)) {
            log_activity((int) $brand['id'], $access ?: 'public', 'permalink', $item['title'] . ' – ' . strtoupper($ext));
        }
    }
    stream_file(stored_file_path($file), $brand['slug'] . '-' . $item['slug'] . '.' . $ext, in_array($ext, array_merge(IMAGE_EXT, ['pdf']), true), (string) $file['mime']);
}

function client_zip(string $slug, ?string $section = null): void
{
    $brand = load_client_brand($slug);
    $access = brand_access($brand);
    if (!$access) {
        abort(403, 'Pro stažení se nejdřív přihlaste.');
    }
    if ($section !== null && !isset(SECTIONS[$section])) {
        abort(404);
    }
    if ($access !== 'admin') {
        log_activity((int) $brand['id'], $access, 'zip', $section ? 'ZIP sekce ' . SECTIONS[$section]['title'] : 'Celý brand manuál (ZIP)');
    }
    send_brand_zip($brand, $section);
}

/** Tisková verze celého manuálu – prohlížeč ji uloží jako PDF. */
function client_print(string $slug): void
{
    $brand = load_client_brand($slug);
    $access = client_guard($brand);
    if ($access !== 'admin') {
        log_activity((int) $brand['id'], $access, 'print', 'Tisková verze manuálu (PDF)');
    }
    $items = brand_items((int) $brand['id'], null, true);
    $bySection = [];
    foreach ($items as $it) {
        $bySection[$it['section']][] = $it;
    }
    echo view('client/print', client_vars($brand, $access) + [
        'title' => $brand['name'] . ' – brand manuál',
        'bySection' => $bySection,
        'colors' => brand_colors((int) $brand['id']),
    ]);
    exit;
}

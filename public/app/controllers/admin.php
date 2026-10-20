<?php
declare(strict_types=1);

/* ================= Přihlášení ================= */

function home(): void
{
    redirect(current_admin() ? '/admin' : '/login');
}

function login_form(): void
{
    if (current_admin()) {
        redirect('/admin');
    }
    render('login', ['error' => null, 'email' => ''], 'plain');
}

function login_submit(): void
{
    $email = trim((string) ($_POST['email'] ?? ''));
    $error = admin_login($email, (string) ($_POST['password'] ?? ''));
    if ($error) {
        render('login', ['error' => $error, 'email' => $email], 'plain');
    }
    $to = (string) ($_SESSION['after_login'] ?? '/admin');
    unset($_SESSION['after_login']);
    redirect(str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/admin');
}

function logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    redirect('/login');
}

/* ================= Přehled ================= */

function admin_dashboard(): void
{
    require_admin();
    cleanup_chunks();
    $q = trim((string) ($_GET['q'] ?? ''));
    $brands = $q === ''
        ? db_all('SELECT * FROM brands ORDER BY is_active DESC, updated_at DESC')
        : db_all('SELECT * FROM brands WHERE name LIKE ? OR slug LIKE ? ORDER BY is_active DESC, updated_at DESC', ['%' . $q . '%', '%' . $q . '%']);
    $incomplete = 0;
    foreach ($brands as &$b) {
        $b['completeness'] = brand_completeness((int) $b['id']);
        $b['symbol'] = brand_symbol((int) $b['id']);
        if ($b['is_active'] && $b['completeness']['done'] < $b['completeness']['total']) {
            $incomplete++;
        }
    }
    unset($b);
    $stats = [
        'brands' => (int) db_val('SELECT COUNT(*) FROM brands WHERE is_active = 1'),
        'files' => (int) db_val('SELECT COUNT(*) FROM item_files f JOIN items i ON i.current_version_id = f.version_id WHERE f.role = ?', ['download']),
        'downloads' => (int) db_val("SELECT COUNT(*) FROM activity WHERE action IN ('download','zip','permalink') AND actor <> 'admin' AND created_at > (NOW() - INTERVAL 30 DAY)"),
        'incomplete' => $incomplete,
    ];
    $activity = db_all('SELECT a.*, b.name AS brand_name FROM activity a LEFT JOIN brands b ON b.id = a.brand_id ORDER BY a.id DESC LIMIT 8');
    $release = json_decode((string) setting('update_last_check', ''), true);
    render('admin/dashboard', [
        'title' => 'Klienti',
        'nav' => 'clients',
        'brands' => $brands,
        'stats' => $stats,
        'activity' => $activity,
        'q' => $q,
        'updateHint' => is_array($release) && isset($release['version']) && version_compare($release['version'], APP_VERSION, '>') ? $release['version'] : null,
    ]);
}

function admin_activity(): void
{
    require_admin();
    $brandId = (int) ($_GET['brand'] ?? 0);
    $rows = $brandId
        ? db_all('SELECT a.*, b.name AS brand_name FROM activity a LEFT JOIN brands b ON b.id = a.brand_id WHERE a.brand_id = ? ORDER BY a.id DESC LIMIT 300', [$brandId])
        : db_all('SELECT a.*, b.name AS brand_name FROM activity a LEFT JOIN brands b ON b.id = a.brand_id ORDER BY a.id DESC LIMIT 300');
    render('admin/activity', ['title' => 'Aktivita', 'nav' => 'activity', 'rows' => $rows, 'brands' => db_all('SELECT id, name FROM brands ORDER BY name'), 'brandId' => $brandId]);
}

/** Náhled symbolu (nebo primárního loga) brandu pro hlavičky a seznamy. */
function brand_symbol(int $brandId): ?array
{
    foreach (['symbol', 'logo-primarni'] as $slug) {
        $item = db_one('SELECT * FROM items WHERE brand_id = ? AND slug = ? AND current_version_id IS NOT NULL', [$brandId, $slug]);
        if ($item) {
            $item = attach_files([$item])[0];
            if ($item['preview']) {
                return $item;
            }
        }
    }
    return null;
}

/* ================= Brandy ================= */

function load_brand_or_404(int|string $id): array
{
    $brand = brand_by_id((int) $id);
    if (!$brand) {
        abort(404, 'Brand neexistuje.');
    }
    return $brand;
}

function admin_brand_new(): void
{
    require_admin();
    render('admin/brand_form', [
        'title' => 'Nový klient',
        'nav' => 'clients',
        'brand' => null,
        'errors' => [],
        'suggestedPassword' => suggest_password(),
    ]);
}

function suggest_password(): string
{
    $words = ['kvas', 'mlyn', 'klas', 'most', 'list', 'dub', 'lipa', 'vlna', 'skala', 'luka', 'brod', 'les', 'rosa', 'zrno'];
    return $words[random_int(0, count($words) - 1)] . '-' . $words[random_int(0, count($words) - 1)] . '-' . random_int(10, 99);
}

function brand_form_input(?array $brand): array
{
    $hero = normalize_hex((string) ($_POST['hero_bg'] ?? '')) ?? '#EAF2FA';
    $domain = strtolower(trim((string) ($_POST['custom_domain'] ?? '')));
    $domain = preg_replace('#^https?://#', '', rtrim($domain, '/')) ?? '';
    return [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'slug' => trim((string) ($_POST['slug'] ?? '')),
        'intro' => trim((string) ($_POST['intro'] ?? '')),
        'contact_name' => trim((string) ($_POST['contact_name'] ?? '')),
        'contact_note' => trim((string) ($_POST['contact_note'] ?? '')),
        'hero_bg' => $hero,
        'custom_domain' => $domain,
        'public_links' => !empty($_POST['public_links']) ? 1 : 0,
        'share_enabled' => !empty($_POST['share_enabled']) ? 1 : 0,
        'share_days' => (int) ($_POST['share_days'] ?? 30),
        'is_active' => !empty($_POST['is_active']) || $brand === null ? 1 : 0,
        'password' => (string) ($_POST['password'] ?? ''),
    ];
}

function validate_brand_input(array $in, int $brandId = 0): array
{
    $errors = [];
    if ($in['name'] === '') {
        $errors[] = 'Vyplňte název klienta.';
    }
    if ($in['custom_domain'] !== '') {
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $in['custom_domain'])) {
            $errors[] = 'Vlastní doména nemá platný tvar (např. brand.klient.cz).';
        } elseif (db_val('SELECT id FROM brands WHERE custom_domain = ? AND id <> ?', [$in['custom_domain'], $brandId])) {
            $errors[] = 'Tuto doménu už používá jiný klient.';
        }
    }
    if ($in['password'] !== '' && mb_strlen($in['password']) < 6) {
        $errors[] = 'Heslo klienta musí mít aspoň 6 znaků.';
    }
    return $errors;
}

function admin_brand_create(): void
{
    require_admin();
    $in = brand_form_input(null);
    $errors = validate_brand_input($in);
    if ($in['password'] === '') {
        $errors[] = 'Nastavte klientovi heslo.';
    }
    if ($errors) {
        render('admin/brand_form', ['title' => 'Nový klient', 'nav' => 'clients', 'brand' => null, 'errors' => $errors, 'suggestedPassword' => $in['password'] ?: suggest_password()]);
    }
    $slug = unique_brand_slug($in['slug'] ?: $in['name']);
    $id = db_insert(
        'INSERT INTO brands (name, slug, intro, contact_name, contact_note, hero_bg, password_hash, share_enabled, share_token, share_expires_at, public_links, custom_domain, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
        [
            $in['name'], $slug, $in['intro'] ?: null, $in['contact_name'] ?: null, $in['contact_note'] ?: null, $in['hero_bg'],
            password_hash($in['password'], PASSWORD_DEFAULT), $in['share_enabled'], new_share_token(),
            $in['share_enabled'] && $in['share_days'] > 0 ? date('Y-m-d H:i:s', strtotime('+' . $in['share_days'] . ' days')) : null,
            $in['public_links'], $in['custom_domain'] ?: null,
        ]
    );
    sync_template($id);
    log_activity($id, 'admin', 'brand', 'Založen brand');
    flash('success', 'Klient je založený. Heslo pro přístup: ' . $in['password']);
    redirect('/admin/brands/' . $id . '/s/logo');
}

function admin_brand_home(string $id): void
{
    redirect('/admin/brands/' . (int) $id . '/s/logo');
}

function admin_brand_section(string $id, string $section): void
{
    require_admin();
    $brand = load_brand_or_404($id);
    if (!isset(SECTIONS[$section])) {
        abort(404);
    }
    $vars = [
        'title' => $brand['name'] . ' – ' . SECTIONS[$section]['title'],
        'nav' => 'clients',
        'brand' => $brand,
        'section' => $section,
        'stats' => brand_section_stats((int) $brand['id']),
    ];
    if ($section === 'barvy') {
        $vars['colors'] = brand_colors((int) $brand['id']);
    } else {
        $vars['items'] = brand_items((int) $brand['id'], $section);
    }
    render('admin/brand_section', $vars);
}

function admin_brand_settings(string $id): void
{
    require_admin();
    $brand = load_brand_or_404($id);
    render('admin/brand_form', ['title' => $brand['name'] . ' – nastavení', 'nav' => 'clients', 'brand' => $brand, 'errors' => [], 'suggestedPassword' => suggest_password()]);
}

function admin_brand_settings_save(string $id): void
{
    require_admin();
    $brand = load_brand_or_404($id);
    $in = brand_form_input($brand);
    $errors = validate_brand_input($in, (int) $brand['id']);
    if ($errors) {
        render('admin/brand_form', ['title' => $brand['name'] . ' – nastavení', 'nav' => 'clients', 'brand' => array_merge($brand, $in), 'errors' => $errors, 'suggestedPassword' => suggest_password()]);
    }
    $slug = $in['slug'] !== '' && $in['slug'] !== $brand['slug'] ? unique_brand_slug($in['slug'], (int) $brand['id']) : $brand['slug'];
    $expires = $brand['share_expires_at'];
    if ($in['share_days'] >= 0 || ($in['share_enabled'] && !$brand['share_enabled'])) {
        $in['share_days'] = max(0, $in['share_days'] < 0 ? 30 : $in['share_days']);
        $expires = $in['share_days'] > 0 ? date('Y-m-d H:i:s', strtotime('+' . $in['share_days'] . ' days')) : null;
    }
    db_exec(
        'UPDATE brands SET name = ?, slug = ?, intro = ?, contact_name = ?, contact_note = ?, hero_bg = ?, share_enabled = ?, share_expires_at = ?,
         public_links = ?, custom_domain = ?, is_active = ?, updated_at = NOW() WHERE id = ?',
        [
            $in['name'], $slug, $in['intro'] ?: null, $in['contact_name'] ?: null, $in['contact_note'] ?: null, $in['hero_bg'],
            $in['share_enabled'], $expires, $in['public_links'], $in['custom_domain'] ?: null, $in['is_active'], $brand['id'],
        ]
    );
    if (!$brand['share_token']) {
        db_exec('UPDATE brands SET share_token = ? WHERE id = ?', [new_share_token(), $brand['id']]);
    }
    if ($in['password'] !== '') {
        db_exec('UPDATE brands SET password_hash = ? WHERE id = ?', [password_hash($in['password'], PASSWORD_DEFAULT), $brand['id']]);
        flash('success', 'Nové heslo klienta: ' . $in['password'] . ' (dosavadní přihlášení klienta skončilo).');
    }
    flash('success', 'Nastavení klienta je uložené.');
    redirect('/admin/brands/' . $brand['id'] . '/settings');
}

function admin_brand_share_regenerate(string $id): void
{
    require_admin();
    $brand = load_brand_or_404($id);
    db_exec('UPDATE brands SET share_token = ? WHERE id = ?', [new_share_token(), $brand['id']]);
    flash('success', 'Vytvořil se nový sdílený odkaz. Starý odkaz už nefunguje.');
    redirect('/admin/brands/' . $brand['id'] . '/settings');
}

function admin_brand_delete(string $id): void
{
    require_admin();
    $brand = load_brand_or_404($id);
    if (trim((string) ($_POST['confirm'] ?? '')) !== $brand['slug']) {
        flash('error', 'Pro smazání opište přesně adresu klienta (' . $brand['slug'] . ').');
        redirect('/admin/brands/' . $brand['id'] . '/settings');
    }
    rrmdir(data_path('files/' . (int) $brand['id']));
    db_exec('DELETE FROM brands WHERE id = ?', [$brand['id']]);
    db_exec('DELETE FROM activity WHERE brand_id = ?', [$brand['id']]);
    flash('success', 'Klient ' . $brand['name'] . ' i všechny jeho soubory jsou smazané.');
    redirect('/admin');
}

function admin_brand_sync(string $id): void
{
    require_admin();
    $brand = load_brand_or_404($id);
    $n = sync_template((int) $brand['id']);
    flash('success', $n ? 'Doplněno pozic ze šablony: ' . $n . '.' : 'Brand už obsahuje všechny pozice ze šablony.');
    $back = (string) ($_POST['back'] ?? '');
    redirect(str_starts_with($back, '/admin/') ? $back : '/admin/brands/' . $brand['id'] . '/s/logo');
}

/* ================= Barvy ================= */

function admin_colors_save(string $id): void
{
    require_admin();
    $brand = load_brand_or_404($id);
    $rows = $_POST['colors'] ?? [];
    $clean = [];
    $errors = [];
    foreach (is_array($rows) ? $rows : [] as $i => $r) {
        $name = trim((string) ($r['name'] ?? ''));
        $hexRaw = trim((string) ($r['hex'] ?? ''));
        if ($name === '' && $hexRaw === '') {
            continue; // prázdný řádek
        }
        if (!empty($r['delete'])) {
            continue;
        }
        $hex = normalize_hex($hexRaw);
        if (!$hex) {
            $errors[] = 'Barva „' . ($name ?: $hexRaw) . '“ nemá platný HEX kód.';
            continue;
        }
        $clean[] = [
            'name' => $name ?: $hex,
            'hex' => $hex,
            'cmyk' => trim((string) ($r['cmyk'] ?? '')),
            'pantone' => trim((string) ($r['pantone'] ?? '')),
            'role' => trim((string) ($r['role'] ?? '')),
            'sort' => (int) ($r['sort'] ?? ($i * 10)),
        ];
    }
    if ($errors) {
        foreach ($errors as $e) {
            flash('error', $e);
        }
        redirect('/admin/brands/' . $brand['id'] . '/s/barvy');
    }
    usort($clean, fn ($a, $b) => $a['sort'] <=> $b['sort']);
    db()->beginTransaction();
    db_exec('DELETE FROM colors WHERE brand_id = ?', [$brand['id']]);
    foreach ($clean as $n => $c) {
        db_exec(
            'INSERT INTO colors (brand_id, name, hex, cmyk, pantone, role, sort) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$brand['id'], mb_substr($c['name'], 0, 80), $c['hex'], mb_substr($c['cmyk'], 0, 40) ?: null, mb_substr($c['pantone'], 0, 40) ?: null, mb_substr($c['role'], 0, 60) ?: null, ($n + 1) * 10]
        );
    }
    db()->commit();
    touch_brand((int) $brand['id']);
    log_activity((int) $brand['id'], 'admin', 'colors', 'Upravené barvy (' . count($clean) . ')');
    flash('success', 'Barvy jsou uložené.');
    redirect('/admin/brands/' . $brand['id'] . '/s/barvy');
}

/* ================= Položky ================= */

function load_item_or_404(int|string $id): array
{
    $item = item_by_id((int) $id);
    if (!$item) {
        abort(404, 'Položka neexistuje.');
    }
    return $item;
}

function admin_item_create(string $brandId): void
{
    require_admin();
    $brand = load_brand_or_404($brandId);
    $section = (string) ($_POST['section'] ?? '');
    $title = trim((string) ($_POST['title'] ?? ''));
    if (!isset(SECTIONS[$section]) || $section === 'barvy' || $title === '') {
        flash('error', 'Vyplňte název nové pozice.');
        redirect('/admin/brands/' . $brand['id'] . '/s/' . ($section ?: 'logo'));
    }
    $sort = (int) db_val('SELECT COALESCE(MAX(sort), 0) + 10 FROM items WHERE brand_id = ? AND section = ?', [$brand['id'], $section]);
    $id = db_insert(
        'INSERT INTO items (brand_id, section, kind, title, slug, description, specs, tags, required, sort, meta, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL, 0, ?, ?, NOW(), NOW())',
        [$brand['id'], $section, $section === 'typografie' ? 'font' : 'file', $title, unique_item_slug((int) $brand['id'], $title), $sort, '{}']
    );
    flash('success', 'Pozice „' . $title . '“ je přidaná. Můžete do ní nahrát soubory.');
    redirect('/admin/brands/' . $brand['id'] . '/s/' . $section . '#item-' . $id);
}

function admin_item_edit(string $id): void
{
    require_admin();
    $item = load_item_or_404($id);
    $brand = load_brand_or_404($item['brand_id']);
    $versions = db_all('SELECT * FROM item_versions WHERE item_id = ? ORDER BY version DESC', [$item['id']]);
    foreach ($versions as &$v) {
        $v['files'] = db_all('SELECT * FROM item_files WHERE version_id = ? ORDER BY role, id', [$v['id']]);
    }
    unset($v);
    render('admin/item_edit', ['title' => $item['title'], 'nav' => 'clients', 'item' => $item, 'brand' => $brand, 'versions' => $versions]);
}

function admin_item_save(string $id): void
{
    require_admin();
    $item = load_item_or_404($id);
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') {
        flash('error', 'Název pozice nesmí být prázdný.');
        redirect('/admin/items/' . $item['id']);
    }
    $slugIn = trim((string) ($_POST['slug'] ?? ''));
    $slug = $slugIn !== '' && $slugIn !== $item['slug'] ? unique_item_slug((int) $item['brand_id'], $slugIn, (int) $item['id']) : $item['slug'];
    $meta = $item['meta'];
    if ($item['kind'] === 'font') {
        $meta['family'] = trim((string) ($_POST['font_family'] ?? ''));
        $meta['weights'] = trim((string) ($_POST['font_weights'] ?? ''));
        $meta['sample'] = trim((string) ($_POST['font_sample'] ?? ''));
        $meta['google_url'] = filter_var(trim((string) ($_POST['font_google_url'] ?? '')), FILTER_VALIDATE_URL) ?: '';
        $meta['license'] = trim((string) ($_POST['font_license'] ?? ''));
    }
    db_exec(
        'UPDATE items SET title = ?, slug = ?, description = ?, specs = ?, tags = ?, required = ?, preview_bg = ?, meta = ?, updated_at = NOW() WHERE id = ?',
        [
            $title, $slug,
            trim((string) ($_POST['description'] ?? '')) ?: null,
            trim((string) ($_POST['specs'] ?? '')) ?: null,
            trim((string) ($_POST['tags'] ?? '')) ?: null,
            !empty($_POST['required']) ? 1 : 0,
            normalize_hex((string) ($_POST['preview_bg'] ?? '')),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            $item['id'],
        ]
    );
    touch_brand((int) $item['brand_id']);
    flash('success', 'Pozice je uložená.' . ($slug !== $item['slug'] ? ' Pozor: změnila se adresa trvalých odkazů.' : ''));
    redirect('/admin/items/' . $item['id']);
}

function admin_item_move(string $id): void
{
    require_admin();
    $item = load_item_or_404($id);
    $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
    $siblings = db_all('SELECT id, sort FROM items WHERE brand_id = ? AND section = ? ORDER BY sort, id', [$item['brand_id'], $item['section']]);
    $ids = array_map(fn ($s) => (int) $s['id'], $siblings);
    $pos = array_search((int) $item['id'], $ids, true);
    $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
    if ($pos !== false && isset($ids[$swap])) {
        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        foreach ($ids as $n => $iid) {
            db_exec('UPDATE items SET sort = ? WHERE id = ?', [($n + 1) * 10, $iid]);
        }
    }
    redirect('/admin/brands/' . $item['brand_id'] . '/s/' . $item['section'] . '#item-' . $item['id']);
}

function admin_item_delete(string $id): void
{
    require_admin();
    $item = load_item_or_404($id);
    rrmdir(data_path('files/' . (int) $item['brand_id'] . '/' . (int) $item['id']));
    db_exec('DELETE FROM items WHERE id = ?', [$item['id']]);
    touch_brand((int) $item['brand_id']);
    flash('success', 'Pozice „' . $item['title'] . '“ i její soubory jsou smazané.');
    redirect('/admin/brands/' . $item['brand_id'] . '/s/' . $item['section']);
}

/* ================= Nahrávání ================= */

function admin_upload_chunk(): void
{
    require_admin();
    try {
        $size = receive_chunk((string) ($_POST['upload_id'] ?? ''), (int) ($_POST['index'] ?? 0), $_FILES['chunk'] ?? []);
        json_response(['ok' => true, 'size' => $size]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

function admin_item_upload(string $id): void
{
    require_admin();
    $item = load_item_or_404($id);
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    try {
        $mode = ($data['mode'] ?? 'new') === 'append' ? 'append' : 'new';
        $versionId = commit_uploads($item, (array) ($data['files'] ?? []), $mode, trim((string) ($data['note'] ?? '')));
        $v = db_one('SELECT version FROM item_versions WHERE id = ?', [$versionId]);
        log_activity((int) $item['brand_id'], 'admin', 'upload', $item['title'] . ' – verze ' . $v['version']);
        flash('success', '„' . $item['title'] . '“: soubory nahrané (verze ' . $v['version'] . '). Klient je vidí hned.');
        json_response(['ok' => true, 'version' => (int) $v['version']]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

/* ================= Verze a soubory ================= */

function admin_version_activate(string $id): void
{
    require_admin();
    $v = db_one('SELECT * FROM item_versions WHERE id = ?', [(int) $id]) ?? abort(404);
    db_exec('UPDATE items SET current_version_id = ?, updated_at = NOW() WHERE id = ?', [$v['id'], $v['item_id']]);
    $item = db_one('SELECT * FROM items WHERE id = ?', [$v['item_id']]);
    touch_brand((int) $item['brand_id']);
    log_activity((int) $item['brand_id'], 'admin', 'restore', $item['title'] . ' – obnovena verze ' . $v['version']);
    flash('success', 'Aktuální je teď verze ' . $v['version'] . '. Klient i trvalé odkazy ji vidí hned.');
    redirect('/admin/items/' . $v['item_id']);
}

function admin_version_delete(string $id): void
{
    require_admin();
    $v = db_one('SELECT * FROM item_versions WHERE id = ?', [(int) $id]) ?? abort(404);
    $item = db_one('SELECT * FROM items WHERE id = ?', [$v['item_id']]);
    if ((int) $item['current_version_id'] === (int) $v['id']) {
        flash('error', 'Aktuální verzi nelze smazat. Nejdřív nastavte jako aktuální jinou verzi.');
    } else {
        delete_version($v);
        flash('success', 'Verze ' . $v['version'] . ' je smazaná.');
    }
    redirect('/admin/items/' . $v['item_id']);
}

function admin_file_delete(string $id): void
{
    require_admin();
    $f = db_one('SELECT * FROM item_files WHERE id = ?', [(int) $id]) ?? abort(404);
    $v = db_one('SELECT * FROM item_versions WHERE id = ?', [$f['version_id']]);
    $path = stored_file_path($f);
    db_exec('DELETE FROM item_files WHERE id = ?', [$f['id']]);
    @unlink($path);
    // Prázdná verze nemá smysl.
    if (!(int) db_val('SELECT COUNT(*) FROM item_files WHERE version_id = ?', [$v['id']])) {
        $item = db_one('SELECT * FROM items WHERE id = ?', [$v['item_id']]);
        if ((int) $item['current_version_id'] === (int) $v['id']) {
            $prev = db_val('SELECT id FROM item_versions WHERE item_id = ? AND id <> ? ORDER BY version DESC LIMIT 1', [$item['id'], $v['id']]);
            db_exec('UPDATE items SET current_version_id = ? WHERE id = ?', [$prev ?: null, $item['id']]);
        }
        delete_version($v);
    }
    flash('success', 'Soubor ' . $f['original_name'] . ' je smazaný.');
    redirect('/admin/items/' . $v['item_id']);
}

function admin_file_get(string $id): void
{
    require_admin();
    $f = db_one('SELECT * FROM item_files WHERE id = ?', [(int) $id]) ?? abort(404);
    stream_file(stored_file_path($f), $f['original_name'], !empty($_GET['inline']), (string) $f['mime']);
}

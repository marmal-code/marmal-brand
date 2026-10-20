<?php
declare(strict_types=1);

/* ================= Profil ================= */

function settings_profile(): void
{
    $admin = require_admin();
    render('admin/settings_profile', ['title' => 'Nastavení', 'nav' => 'settings', 'tab' => 'profile', 'me' => $admin]);
}

function settings_profile_save(): void
{
    $admin = require_admin();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $user = db_one('SELECT * FROM users WHERE id = ?', [$admin['id']]);

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Vyplňte jméno a platný e-mail.');
        redirect('/admin/settings');
    }
    if ($email !== $user['email'] && db_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $admin['id']])) {
        flash('error', 'Tento e-mail už používá jiný účet.');
        redirect('/admin/settings');
    }
    if ($new !== '') {
        if (!password_verify($current, $user['password_hash'])) {
            flash('error', 'Současné heslo nesouhlasí, nové heslo se neuložilo.');
            redirect('/admin/settings');
        }
        if (mb_strlen($new) < 10) {
            flash('error', 'Nové heslo musí mít aspoň 10 znaků.');
            redirect('/admin/settings');
        }
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
    }
    db_exec('UPDATE users SET name = ?, email = ? WHERE id = ?', [$name, $email, $admin['id']]);
    flash('success', $new !== '' ? 'Profil i nové heslo jsou uložené.' : 'Profil je uložený.');
    redirect('/admin/settings');
}

/* ================= Aktualizace ================= */

function settings_update(): void
{
    require_admin();
    $last = json_decode((string) setting('update_last_check', ''), true);
    $result = $_SESSION['update_result'] ?? null;
    unset($_SESSION['update_result']);
    render('admin/settings_update', [
        'title' => 'Aktualizace',
        'nav' => 'settings',
        'tab' => 'update',
        'repo' => update_repo(),
        'hasToken' => github_token() !== '',
        'release' => is_array($last) ? $last : null,
        'lastLog' => (string) setting('update_last_log', ''),
        'backups' => list_backups(),
        'checks' => requirements(),
        'result' => $result,
    ]);
}

function settings_update_token(): void
{
    require_admin();
    $repo = trim((string) ($_POST['repo'] ?? ''));
    if ($repo !== '' && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
        set_setting('github_repo', $repo);
    }
    $token = trim((string) ($_POST['token'] ?? ''));
    if (!empty($_POST['remove_token'])) {
        set_setting('github_token', null);
        flash('success', 'Token je odebraný.');
    } elseif ($token !== '') {
        set_setting('github_token', encrypt_secret($token));
        flash('success', 'Token je uložený (zašifrovaně). Teď můžete zkontrolovat aktualizace.');
    } else {
        flash('success', 'Nastavení repozitáře je uložené.');
    }
    redirect('/admin/settings/update');
}

function settings_update_check(): void
{
    require_admin();
    unset($_SESSION['update_result']);
    try {
        $r = latest_release();
        flash('success', update_available($r)
            ? 'K dispozici je nová verze ' . $r['version'] . '.'
            : 'Aplikace je aktuální (verze ' . APP_VERSION . ').');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/settings/update');
}

function settings_update_run(): void
{
    require_admin();
    $tag = (string) ($_POST['tag'] ?? '');
    try {
        $log = run_update($tag);
        $_SESSION['update_result'] = ['ok' => true, 'log' => $log];
        flash('success', 'Aktualizace proběhla.');
    } catch (Throwable $e) {
        $_SESSION['update_result'] = ['ok' => false, 'log' => explode("\n", $e->getMessage())];
        flash('error', 'Aktualizace se nepovedla. Podrobnosti jsou v protokolu níže.');
    }
    redirect('/admin/settings/update');
}

function settings_backup_create(): void
{
    require_admin();
    try {
        $id = create_backup('Ruční záloha');
        flash('success', 'Záloha ' . $id . ' je hotová.');
    } catch (Throwable $e) {
        flash('error', 'Zálohu se nepodařilo vytvořit: ' . $e->getMessage());
    }
    redirect('/admin/settings/update');
}

function settings_backup_restore(): void
{
    require_admin();
    $id = (string) ($_POST['backup'] ?? '');
    try {
        restore_backup($id);
        flash('success', 'Záloha ' . $id . ' je obnovená (kód i databáze).');
    } catch (Throwable $e) {
        flash('error', 'Obnova se nepovedla: ' . $e->getMessage());
    }
    redirect('/admin/settings/update');
}

/* ================= Šablona sekcí ================= */

function settings_template(): void
{
    require_admin();
    $rows = db_all('SELECT * FROM template_items ORDER BY section, sort, id');
    $bySection = [];
    foreach ($rows as $r) {
        $bySection[$r['section']][] = $r;
    }
    render('admin/template', ['title' => 'Šablona sekcí', 'nav' => 'template', 'bySection' => $bySection]);
}

function settings_template_save(): void
{
    require_admin();
    foreach ((array) ($_POST['t'] ?? []) as $id => $r) {
        $title = trim((string) ($r['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        db_exec(
            'UPDATE template_items SET title = ?, description = ?, specs = ?, tags = ?, required = ?, sort = ? WHERE id = ?',
            [$title, trim((string) ($r['description'] ?? '')) ?: null, trim((string) ($r['specs'] ?? '')) ?: null, trim((string) ($r['tags'] ?? '')) ?: null, !empty($r['required']) ? 1 : 0, (int) ($r['sort'] ?? 0), (int) $id]
        );
    }
    flash('success', 'Šablona je uložená. Změny platí pro nově zakládané klienty a pro „Doplnit ze šablony“.');
    redirect('/admin/template');
}

function settings_template_create(): void
{
    require_admin();
    $section = (string) ($_POST['section'] ?? '');
    $title = trim((string) ($_POST['title'] ?? ''));
    if (!isset(SECTIONS[$section]) || $section === 'barvy' || $title === '') {
        flash('error', 'Vyberte sekci a vyplňte název pozice.');
        redirect('/admin/template');
    }
    $slug = slugify($title);
    $n = 2;
    while (db_val('SELECT id FROM template_items WHERE slug = ?', [$slug])) {
        $slug = slugify($title) . '-' . $n++;
    }
    $sort = (int) db_val('SELECT COALESCE(MAX(sort), 0) + 10 FROM template_items WHERE section = ?', [$section]);
    db_exec(
        'INSERT INTO template_items (section, kind, title, slug, description, specs, tags, required, sort) VALUES (?, ?, ?, ?, NULL, NULL, NULL, 0, ?)',
        [$section, $section === 'typografie' ? 'font' : 'file', $title, $slug, $sort]
    );
    flash('success', 'Pozice „' . $title . '“ je přidaná do šablony.');
    redirect('/admin/template#s-' . $section);
}

function settings_template_delete(string $id): void
{
    require_admin();
    db_exec('DELETE FROM template_items WHERE id = ?', [(int) $id]);
    flash('success', 'Pozice je odebraná ze šablony. Stávajících klientů se to netýká.');
    redirect('/admin/template');
}

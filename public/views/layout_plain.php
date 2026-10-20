<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(session_status() === PHP_SESSION_ACTIVE ? csrf_token() : '') ?>">
<title><?= e($title ?? 'Brand Hub') ?> · Brand Hub</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lexend:wght@200;300;500&display=swap">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<div class="plain-wrap">
<?= $content ?>
</div>
<script src="/assets/js/app.js?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>

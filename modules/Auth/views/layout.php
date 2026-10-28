<?php
use App\Session\Session;
$session = app(Session::class);
$error = $session->pullFlash('error');
$errors = $session->pullFlash('errors', []);
$success = $session->pullFlash('success');
$user = $auth->user();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · Tunisian Football Manager</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app.js" defer></script>
    <script src="/assets/js/auth.js" defer></script>
</head>
<body class="auth-body">
<header class="auth-header">
    <a class="brand" href="/"><span class="brand__mark">TFM</span><span>Tunisian Football Manager</span></a>
    <?php if ($user): ?>
        <nav class="auth-nav"><a href="/saves">Mes parties</a><span><?= e($user['name']) ?></span>
            <form method="post" action="/logout"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="btn btn--ghost btn--sm">Déconnexion</button></form>
        </nav>
    <?php endif; ?>
</header>
<main class="auth-main">
    <?php if ($error): ?><div class="alert alert--danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert--danger"><strong>Veuillez corriger le formulaire :</strong><ul><?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?= $content ?>
</main>
<footer class="auth-footer">La simulation de management du football tunisien · PHP natif</footer>
</body>
</html>

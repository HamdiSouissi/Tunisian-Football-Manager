<?php
/** @var list<array{name: string, version: string, priority: int}> $modules */
/** @var string $phpVersion */
$locale = (string) config('app.locale', 'fr');
?>
<!doctype html>
<html lang="<?= e($locale) ?>" dir="<?= $locale === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e(config('app.name')) ?> · <?= e(__('status.eyebrow')) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<main class="shell">
    <header class="hero">
        <a class="brand" href="/" aria-label="<?= e(config('app.name')) ?>">
            <span class="brand__mark">TFM</span>
            <span><?= e(config('app.name')) ?></span>
        </a>
        <span class="badge badge--success"><?= e(__('status.kernel_ready')) ?></span>
        <p class="eyebrow"><?= e(__('status.eyebrow')) ?></p>
        <h1><?= e(__('status.title')) ?></h1>
        <p class="hero__description"><?= e(__('status.description')) ?></p>
    </header>

    <section class="grid" aria-label="<?= e(__('status.environment')) ?>">
        <article class="card">
            <div class="card__heading">
                <span class="icon" aria-hidden="true">◇</span>
                <h2><?= e(__('status.foundation')) ?></h2>
            </div>
            <ul class="check-list">
                <li><?= e(__('status.feature_mvc')) ?></li>
                <li><?= e(__('status.feature_pdo')) ?></li>
                <li><?= e(__('status.feature_security')) ?></li>
                <li><?= e(__('status.feature_hooks')) ?></li>
            </ul>
        </article>

        <article class="card">
            <div class="card__heading">
                <span class="icon" aria-hidden="true">▦</span>
                <h2><?= e(__('status.environment')) ?></h2>
            </div>
            <dl class="facts">
                <div><dt>PHP</dt><dd><?= e($phpVersion) ?></dd></div>
                <div><dt><?= e(__('status.environment_name')) ?></dt><dd><?= e(config('app.env')) ?></dd></div>
                <div><dt><?= e(__('status.modules')) ?></dt><dd><?= count($modules) ?></dd></div>
            </dl>
            <div class="module-list">
                <?php foreach ($modules as $module): ?>
                    <span class="badge"><?= e($module['name']) ?> · <?= e($module['version']) ?></span>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="card card--wide">
            <div class="card__heading">
                <span class="icon" aria-hidden="true">⌁</span>
                <h2><?= e(__('status.database')) ?></h2>
            </div>
            <p><?= e(__('status.database_help')) ?></p>
            <div class="command"><code>php bin/migrate</code></div>
            <a class="link" href="/health"><?= e(__('status.health_link')) ?> →</a>
        </article>
    </section>

    <footer><?= e(__('status.footer')) ?></footer>
</main>
</body>
</html>

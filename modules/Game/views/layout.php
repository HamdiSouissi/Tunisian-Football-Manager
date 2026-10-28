<?php
use App\Session\Session;
$save = $context->save(); $club = $context->club(); $session = app(Session::class);
$success = $session->pullFlash('success'); $error = $session->pullFlash('error');
$links = [
    '' => ['dashboard','⌂','Accueil'], 'inbox' => ['inbox','✉','Boîte de réception'],
    'squad' => ['squad','♟','Effectif'], 'tactics' => ['tactics','▧','Tactique'],
    'training' => ['training','↗','Entraînement'], 'transfers' => ['transfers','⇄','Transferts'],
    'calendar' => ['calendar','▤','Calendrier'], 'standings' => ['standings','≣','Classement'],
    'finances' => ['finances','◈','Finances'], 'club' => ['club','◇','Club'], 'staff' => ['staff','♙','Staff'],
];
$base = '/saves/' . (int) $save['id'];
?>
<!doctype html><html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · <?= e($club['short_name']) ?> · TFM</title>
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/js/app.js" defer></script><script src="/assets/js/auth.js" defer></script>
<script src="/assets/js/player.js" defer></script><script src="/assets/js/tactics.js" defer></script><script src="/assets/js/match.js" defer></script>
</head><body class="game-body" style="--club-color: <?= e($club['primary_color']) ?>; --club-secondary: <?= e($club['secondary_color']) ?>">
<aside class="sidebar" id="sidebar"><a class="brand sidebar__brand" href="<?= $base ?>"><span class="brand__mark brand__mark--club"><?= club_logo($club['slug'], 'club-logo', false) ?></span><span><strong><?= e($club['name']) ?></strong><small><?= e($save['manager_name']) ?></small></span></a>
<nav class="sidebar__nav"><?php foreach ($links as $path => [$key,$icon,$label]): ?><a class="<?= $active === $key ? 'active' : '' ?>" href="<?= $base . ($path ? '/' . $path : '') ?>"><span><?= $icon ?></span><?= e($label) ?><?php if ($key === 'inbox' && $context->unreadMessages()): ?><b class="nav-badge"><?= $context->unreadMessages() ?></b><?php endif; ?></a><?php endforeach; ?></nav>
<div class="sidebar__footer"><a href="/saves">← Changer de partie</a><small><?= e($save['name']) ?></small></div></aside>
<div class="game-shell"><header class="topbar"><button class="icon-button" data-sidebar-toggle aria-label="Menu">☰</button><div class="topbar__date"><small>Date de jeu</small><strong><?= format_date($save['current_date'], 'd M Y') ?></strong></div>
<div class="topbar__finance"><small>Budget transferts</small><strong><?= format_money($club['transfer_budget']) ?></strong></div><div class="topbar__finance topbar__finance--optional"><small>Solde</small><strong><?= format_money($club['balance']) ?></strong></div>
<div class="continue-group"><form method="post" action="<?= $base ?>/continue"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="days" value="1"><button class="btn btn--ghost btn--sm" <?= $save['status'] !== 'active' ? 'disabled' : '' ?>>＋1 jour</button></form>
<form method="post" action="<?= $base ?>/continue"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="days" value="7"><button class="btn btn--primary" <?= $save['status'] !== 'active' ? 'disabled' : '' ?>>Continuer <span>→</span></button></form></div></header>
<main class="game-content"><?php if ($success): ?><div class="alert alert--success"><?= e($success) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert--danger"><?= e($error) ?></div><?php endif; ?><?= $content ?></main></div>
</body></html>

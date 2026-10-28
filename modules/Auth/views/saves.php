<div class="page-heading"><div><span class="eyebrow">Centre de carrières</span><h1>Mes parties</h1><p>Chaque sauvegarde possède son propre monde, ses joueurs et son calendrier.</p></div><a class="btn btn--primary" href="/saves/create">＋ Nouvelle partie</a></div>
<?php if (!$saves): ?><div class="empty-state"><span>⚽</span><h2>Aucune carrière pour le moment</h2><p>Choisissez votre premier club et lancez l’aventure.</p><a class="btn btn--primary" href="/saves/create">Commencer</a></div><?php endif; ?>
<div class="save-grid">
<?php foreach ($saves as $save): ?>
    <article class="save-card" style="--club-color: <?= e($save['primary_color'] ?? '#14b8a6') ?>">
        <div class="save-card__top"><span class="badge"><?= e($save['division_name'] ?? 'Initialisation') ?></span><span class="badge badge--muted"><?= e(ucfirst((string) $save['difficulty'])) ?></span></div>
        <h2><?= e($save['name']) ?></h2><p class="save-card__club"><?= e($save['club_name'] ?? 'Monde incomplet') ?></p>
        <dl class="save-card__facts"><div><dt>Entraîneur</dt><dd><?= e($save['manager_name']) ?></dd></div><div><dt>Date de jeu</dt><dd><?= e(date('d/m/Y', strtotime((string) $save['current_date']))) ?></dd></div><div><dt>Statut</dt><dd><?= e($save['status']) ?></dd></div></dl>
        <div class="save-card__actions"><a class="btn btn--primary" href="/saves/<?= (int) $save['id'] ?>">Reprendre</a>
            <?php if ($save['status'] === 'completed'): ?><span class="badge badge--muted">Carrière terminée</span><?php else: ?><form method="post" action="/saves/<?= (int) $save['id'] ?>/archive"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="btn btn--ghost btn--sm"><?= $save['status'] === 'archived' ? 'Restaurer' : 'Archiver' ?></button></form><?php endif; ?>
        </div>
    </article>
<?php endforeach; ?>
</div>

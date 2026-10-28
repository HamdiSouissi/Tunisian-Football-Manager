<?php
$base = '/saves/' . (int)$context->saveId() . '/matches/' . (int)$fixture['id'];
$status = $state['status'] ?? $fixture['status'];
$canPlay = format_date($fixture['scheduled_at'], 'Y-m-d') <= $context->save()['current_date'];
$homeScore = $state['home_score'] ?? ($fixture['home_score'] ?? 0);
$awayScore = $state['away_score'] ?? ($fixture['away_score'] ?? 0);
$emptyStats = ['possession' => 0, 'shots' => 0, 'on_target' => 0, 'fouls' => 0, 'yellow' => 0, 'red' => 0];
$stats = $state['stats'] ?? ['home' => $emptyStats, 'away' => $emptyStats];
$userSide = $state['sides'][$side] ?? null;
$tactic = $userSide['tactic'] ?? null;
$statusLabels = ['scheduled' => 'Avant-match', 'live' => 'En direct', 'halftime' => 'Mi-temps', 'finished' => 'Terminé'];
$mentalityLabels = ['very_defensive' => 'Très défensive', 'defensive' => 'Défensive', 'balanced' => 'Équilibrée', 'attacking' => 'Offensive', 'very_attacking' => 'Très offensive'];
$styleLabels = ['mixed' => 'Mixte', 'possession' => 'Possession', 'direct' => 'Jeu direct', 'counter' => 'Contre-attaque', 'wings' => 'Jeu sur les ailes', 'long_ball' => 'Jeu long'];
?>
<div id="match-root" data-status="<?= e($status) ?>" data-side="<?= e($side) ?>" data-step-url="<?= $base ?>/step" data-resume-url="<?= $base ?>/resume">
    <div class="match-header">
        <span class="eyebrow">Journée <?= (int)$fixture['matchday'] ?> · <?= format_date($fixture['scheduled_at'], 'd/m/Y H:i') ?></span>
        <div class="scoreboard">
            <div><span class="club-crest club-crest--large" style="--crest:<?= e($fixture['home_color']) ?>"><?= club_logo($fixture['home_slug'], 'club-logo', false) ?></span><h1><?= e($fixture['home_name']) ?></h1></div>
            <div class="scoreboard__score"><strong id="home-score"><?= (int)$homeScore ?></strong><span>–</span><strong id="away-score"><?= (int)$awayScore ?></strong><small id="match-minute"><?= $status === 'scheduled' ? 'Avant-match' : ($status === 'finished' ? 'Terminé' : (int)($state['minute'] ?? 0) . '′') ?></small></div>
            <div><span class="club-crest club-crest--large" style="--crest:<?= e($fixture['away_color']) ?>"><?= club_logo($fixture['away_slug'], 'club-logo', false) ?></span><h1><?= e($fixture['away_name']) ?></h1></div>
        </div>
    </div>

    <?php if ($status === 'scheduled'): ?>
        <section class="panel match-start">
            <div class="panel__body">
                <h2>Le vestiaire vous attend</h2>
                <p>Simulez immédiatement la rencontre ou prenez place sur le banc pour la jouer minute par minute.</p>
                <a class="btn btn--ghost" href="/saves/<?= (int)$context->saveId() ?>/tactics">Voir la tactique</a>
                <?php if ($canPlay): ?>
                    <form method="post" action="<?= $base ?>/simulate">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <button class="btn btn--primary" type="submit">Jouer le match</button>
                    </form>
                    <form method="post" action="<?= $base ?>/start">
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <button class="btn btn--ghost" type="submit">Coup d’envoi en direct</button>
                    </form>
                <?php else: ?>
                    <span class="badge">Jouable le <?= format_date($fixture['scheduled_at'], 'd/m/Y') ?></span>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <div class="match-controls">
            <button class="btn btn--primary" id="match-step" <?= in_array($status, ['finished', 'halftime'], true) ? 'disabled' : '' ?>>Jouer 1 minute</button>
            <button class="btn btn--ghost" id="match-auto" <?= in_array($status, ['finished', 'halftime'], true) ? 'disabled' : '' ?>>▶ Lecture auto</button>
            <form id="resume-form" class="inline-form <?= $status === 'halftime' ? '' : 'is-hidden' ?>"><button class="btn btn--primary">Reprendre la seconde période</button></form>
            <span class="badge" id="match-status-badge"><?= e($statusLabels[$status] ?? $status) ?></span>
            <span class="match-feedback" id="match-feedback" role="status" aria-live="polite"></span>
        </div>
    <?php endif; ?>

    <div class="match-grid">
        <section class="panel">
            <div class="panel__header"><h2>Commentaires en direct</h2><span>Le fil reste disponible pendant vos ajustements</span></div>
            <div class="panel__body"><div class="commentary" id="match-events"><?php foreach ($events as $event): ?><div class="commentary__item commentary__item--<?= e($event['type']) ?>"><span><?= (int)$event['minute'] ?>′</span><p><?= e($event['description']) ?></p></div><?php endforeach; ?></div></div>
        </section>

        <aside>
            <section class="panel">
                <div class="panel__header"><h2>Statistiques</h2></div>
                <div class="panel__body match-stats" id="match-stats">
                    <?php $totalPoss = max(1, $stats['home']['possession'] + $stats['away']['possession']); ?>
                    <div><span id="home-possession"><?= round($stats['home']['possession'] * 100 / $totalPoss) ?>%</span><label>Possession</label><span id="away-possession"><?= round($stats['away']['possession'] * 100 / $totalPoss) ?>%</span></div>
                    <div><span id="home-shots"><?= (int)$stats['home']['shots'] ?></span><label>Tirs</label><span id="away-shots"><?= (int)$stats['away']['shots'] ?></span></div>
                    <div><span id="home-on-target"><?= (int)$stats['home']['on_target'] ?></span><label>Tirs cadrés</label><span id="away-on-target"><?= (int)$stats['away']['on_target'] ?></span></div>
                    <div><span id="home-fouls"><?= (int)$stats['home']['fouls'] ?></span><label>Fautes</label><span id="away-fouls"><?= (int)$stats['away']['fouls'] ?></span></div>
                    <div><span id="home-yellow"><?= (int)$stats['home']['yellow'] ?></span><label>Cartons jaunes</label><span id="away-yellow"><?= (int)$stats['away']['yellow'] ?></span></div>
                    <div><span id="home-red"><?= (int)$stats['home']['red'] ?></span><label>Cartons rouges</label><span id="away-red"><?= (int)$stats['away']['red'] ?></span></div>
                </div>
            </section>

            <?php if ($userSide && $tactic && $status !== 'finished'): ?>
                <section class="panel live-coach">
                    <div class="panel__header"><h2>Banc du coach</h2><span class="badge badge--success" id="live-formation"><?= e($tactic['formation']) ?></span></div>
                    <div class="panel__body">
                        <form id="substitution-form" data-url="<?= $base ?>/substitution" class="form-stack">
                            <h3>Remplacement <small id="substitution-count"><?= (int)$userSide['substitutions'] ?>/5</small></h3>
                            <label class="field"><span>Joueur sortant</span><select name="out_player" id="out-player" required><?php foreach ($tactic['lineup'] as $entry): $p = $userSide['lineup'][(int)$entry['player_id']] ?? null; if ($p): ?><option value="<?= (int)$entry['player_id'] ?>"><?= e($p['name'] . ' · ' . $entry['position']) ?></option><?php endif; endforeach; ?></select></label>
                            <label class="field"><span>Joueur entrant</span><select name="in_player" id="in-player" required><?php foreach ($tactic['substitutes'] as $entry): $p = $userSide['pool'][(int)$entry['player_id']] ?? null; if ($p): ?><option value="<?= (int)$entry['player_id'] ?>"><?= e($p['first_name'] . ' ' . $p['last_name'] . ' · ' . $p['primary_position']) ?></option><?php endif; endforeach; ?></select></label>
                            <button class="btn btn--ghost" id="substitution-submit" <?= (int)$userSide['substitutions'] >= 5 || !$tactic['substitutes'] ? 'disabled' : '' ?>>Effectuer le remplacement</button>
                        </form>
                        <hr>
                        <form id="live-tactic-form" data-url="<?= $base ?>/tactic" class="form-stack">
                            <h3>Organisation et consignes</h3>
                            <label class="field"><span>Formation</span><select name="formation" id="live-formation-select"><?php foreach ($formations as $name => $slots): ?><option value="<?= e($name) ?>" <?= $tactic['formation'] === $name ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></label>
                            <div class="form-grid form-grid--two">
                                <label class="field"><span>Mentalité</span><select name="mentality"><?php foreach ($mentalityLabels as $option => $label): ?><option value="<?= $option ?>" <?= $tactic['mentality'] === $option ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                                <label class="field"><span>Style</span><select name="playing_style"><?php foreach ($styleLabels as $option => $label): ?><option value="<?= $option ?>" <?= $tactic['playing_style'] === $option ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                                <label class="field"><span>Pressing</span><select name="pressing"><?php foreach (['low' => 'Faible', 'medium' => 'Moyen', 'high' => 'Élevé'] as $option => $label): ?><option value="<?= $option ?>" <?= $tactic['pressing'] === $option ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                                <label class="field"><span>Ligne défensive</span><select name="defensive_line"><?php foreach (['deep' => 'Basse', 'normal' => 'Normale', 'high' => 'Haute'] as $option => $label): ?><option value="<?= $option ?>" <?= $tactic['defensive_line'] === $option ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                                <label class="field"><span>Tempo</span><select name="tempo"><?php foreach (['slow' => 'Lent', 'normal' => 'Normal', 'fast' => 'Rapide'] as $option => $label): ?><option value="<?= $option ?>" <?= $tactic['tempo'] === $option ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                                <label class="field"><span>Largeur</span><select name="width"><?php foreach (['narrow' => 'Étroite', 'normal' => 'Normale', 'wide' => 'Large'] as $option => $label): ?><option value="<?= $option ?>" <?= $tactic['width'] === $option ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                            </div>
                            <button class="btn btn--primary" id="tactic-submit">Appliquer en direct</button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>

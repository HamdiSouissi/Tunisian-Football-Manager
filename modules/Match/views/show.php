<?php
$base = '/saves/' . (int)$context->saveId() . '/matches/' . (int)$fixture['id'];
$status = $state['status'] ?? $fixture['status'];
$canPlay = format_date($fixture['scheduled_at'], 'Y-m-d') <= $context->save()['current_date'];
$homeScore = $state['home_score'] ?? ($fixture['home_score'] ?? 0);
$awayScore = $state['away_score'] ?? ($fixture['away_score'] ?? 0);
$emptyStats = ['possession'=>0,'chances'=>0,'shots'=>0,'on_target'=>0,'passes'=>0,'passes_completed'=>0,'crosses'=>0,'corners'=>0,'dribbles'=>0,'tackles'=>0,'interceptions'=>0,'offsides'=>0,'fouls'=>0,'yellow'=>0,'red'=>0,'injuries'=>0];
$stats = [
    'home' => array_merge($emptyStats, $state['stats']['home'] ?? []),
    'away' => array_merge($emptyStats, $state['stats']['away'] ?? []),
];
$userSide = $state['sides'][$side] ?? null;
$tactic = $userSide['tactic'] ?? null;
$initialPayload = $initialPayload ?? null;
$displayMinute = $initialPayload['display_minute'] ?? (string)($state['minute'] ?? 0);
$weather = $initialPayload['visual']['weather'] ?? null;
$manOfTheMatch = $initialPayload['man_of_the_match'] ?? null;
$ratingPlayers = $initialPayload['sides'][$side]['appearances'] ?? [];
if (!$ratingPlayers && $userSide && $tactic) {
    foreach ($tactic['lineup'] as $entry) {
        $playerId = (int)$entry['player_id'];
        $player = $userSide['lineup'][$playerId] ?? null;
        if ($player) $ratingPlayers[] = ['id'=>$playerId,'name'=>$player['name'],'rating'=>$state['player_ratings'][$side][$playerId] ?? 6.0,'minutes'=>null,'experience'=>0];
    }
}
$statusLabels = ['scheduled' => 'Avant-match', 'live' => 'En direct', 'halftime' => 'Mi-temps', 'finished' => 'Terminé'];
$mentalityLabels = ['very_defensive' => 'Très défensive', 'defensive' => 'Défensive', 'balanced' => 'Équilibrée', 'attacking' => 'Offensive', 'very_attacking' => 'Très offensive'];
$styleLabels = ['mixed' => 'Mixte', 'possession' => 'Possession', 'direct' => 'Jeu direct', 'counter' => 'Contre-attaque', 'wings' => 'Jeu sur les ailes', 'long_ball' => 'Jeu long'];
?>
<div id="match-root" data-status="<?= e($status) ?>" data-side="<?= e($side) ?>" data-step-url="<?= $base ?>/step" data-resume-url="<?= $base ?>/resume" data-simulate-url="<?= $base ?>/simulate" data-home-color="<?= e($fixture['home_color']) ?>" data-away-color="<?= e($fixture['away_color']) ?>">
    <div class="match-header">
        <span class="eyebrow">Journée <?= (int)$fixture['matchday'] ?> · <?= format_date($fixture['scheduled_at'], 'd/m/Y H:i') ?></span>
        <div class="scoreboard">
            <div><span class="club-crest club-crest--large" style="--crest:<?= e($fixture['home_color']) ?>"><?= club_logo($fixture['home_slug'], 'club-logo', false) ?></span><h1><?= e($fixture['home_name']) ?></h1></div>
            <div class="scoreboard__score"><strong id="home-score"><?= (int)$homeScore ?></strong><span>–</span><strong id="away-score"><?= (int)$awayScore ?></strong><small id="match-minute"><?= $status === 'scheduled' ? 'Avant-match' : ($status === 'finished' ? 'Terminé' : e($displayMinute) . '′') ?></small></div>
            <div><span class="club-crest club-crest--large" style="--crest:<?= e($fixture['away_color']) ?>"><?= club_logo($fixture['away_slug'], 'club-logo', false) ?></span><h1><?= e($fixture['away_name']) ?></h1></div>
        </div>
        <?php if ($weather): ?><span class="badge match-weather" id="match-weather" title="La météo influence les passes et la dépense physique"><?= e($weather['icon'] ?? '☀') ?> <?= e($weather['label']) ?> · <?= (int)($weather['temperature'] ?? 0) ?>°C · vent <?= (int)($weather['wind'] ?? 0) ?> km/h</span><?php else: ?><span class="badge match-weather" id="match-weather"></span><?php endif; ?>
    </div>

    <?php if ($status === 'scheduled'): ?>
        <section class="panel match-start"><div class="panel__body"><h2>Le vestiaire vous attend</h2><p>Prenez place sur le banc pour jouer minute par minute, ou simulez immédiatement la rencontre.</p><a class="btn btn--ghost" href="/saves/<?= (int)$context->saveId() ?>/tactics">Voir la tactique</a><?php if ($canPlay): ?><form method="post" action="<?= $base ?>/start"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="btn btn--primary" type="submit">Jouer le match</button></form><form method="post" action="<?= $base ?>/simulate"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="btn btn--ghost" type="submit">Simuler instantanément</button></form><?php else: ?><span class="badge">Jouable le <?= format_date($fixture['scheduled_at'], 'd/m/Y') ?></span><?php endif; ?></div></section>
    <?php else: ?>
        <div class="match-controls">
            <button class="btn btn--primary" id="match-auto" <?= in_array($status, ['finished', 'halftime'], true) ? 'disabled' : '' ?>>▶ Lecture</button>
            <button class="btn btn--ghost" id="match-pause" <?= in_array($status, ['finished', 'halftime'], true) ? 'disabled' : '' ?>>⏸ Pause</button>
            <button class="btn btn--ghost" id="match-step" <?= in_array($status, ['finished', 'halftime'], true) ? 'disabled' : '' ?>>＋1 minute</button>
            <div class="match-speed" role="group" aria-label="Vitesse de simulation">
                <?php foreach ([1,2,4,8,16] as $speed): ?><button type="button" data-speed="<?= $speed ?>" class="<?= $speed === 1 ? 'active' : '' ?>" aria-pressed="<?= $speed === 1 ? 'true' : 'false' ?>">×<?= $speed ?></button><?php endforeach; ?>
            </div>
            <form id="resume-form" class="inline-form <?= $status === 'halftime' ? '' : 'is-hidden' ?>"><button class="btn btn--primary">Reprendre la seconde période</button></form>
            <?php if ($status !== 'finished'): ?><form method="post" action="<?= $base ?>/simulate" id="skip-match-form" data-confirm="Passer directement au résultat final ?"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="btn btn--ghost" id="match-skip" type="submit">Passer le match</button></form><?php endif; ?>
            <span class="badge" id="match-status-badge"><?= e($statusLabels[$status] ?? $status) ?></span>
            <span class="match-feedback" id="match-feedback" role="status" aria-live="polite"></span>
        </div>

        <section class="panel match-arena">
            <div class="panel__header"><h2>Simulation tactique · 105 × 68 m</h2><span id="match-possessor">Ballon au milieu</span></div>
            <div class="match-pitch-wrap">
                <canvas id="match-pitch" width="1050" height="680" role="img" aria-label="Terrain 2D animé représentant les positions calculées par le moteur">Votre navigateur doit prendre en charge Canvas pour afficher le terrain.</canvas>
                <div class="match-loader is-hidden" id="match-loader"><span></span><small>Calcul de la séquence…</small></div>
            </div>
            <div class="match-arena-footer">
                <div class="match-legend"><span><i style="--dot:<?= e($fixture['home_color']) ?>"></i><?= e($fixture['home_short']) ?></span><span><i style="--dot:<?= e($fixture['away_color']) ?>"></i><?= e($fixture['away_short']) ?></span><span><i class="ball-dot"></i>Ballon</span></div>
                <label class="match-names"><input type="checkbox" id="match-names" checked> Afficher les noms</label>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($manOfTheMatch): ?><section class="panel motm-panel"><div class="panel__body"><span class="eyebrow">Homme du match</span><strong><?= e($manOfTheMatch['name']) ?></strong><span class="rating rating--high"><?= number_format((float)$manOfTheMatch['rating'], 1, ',', '') ?></span></div></section><?php endif; ?>

    <div class="match-grid">
        <section class="panel">
            <div class="panel__header"><h2>Commentaires en direct</h2><span>Chaque commentaire est produit par le moteur</span></div>
            <div class="panel__body"><div class="commentary" id="match-events"><?php foreach ($events as $event): ?><div class="commentary__item commentary__item--<?= e($event['type']) ?>"><span><?= (int)$event['minute'] ?>′</span><p><?= e($event['description']) ?></p></div><?php endforeach; ?></div></div>
        </section>

        <aside>
            <section class="panel">
                <div class="panel__header"><h2>Statistiques</h2><span>Domicile / extérieur</span></div>
                <div class="panel__body match-stats" id="match-stats">
                    <?php $totalPoss = max(1, $stats['home']['possession'] + $stats['away']['possession']); ?>
                    <div><span id="home-possession"><?= round($stats['home']['possession'] * 100 / $totalPoss) ?>%</span><label>Possession</label><span id="away-possession"><?= round($stats['away']['possession'] * 100 / $totalPoss) ?>%</span></div>
                    <div><span id="home-shots"><?= (int)$stats['home']['shots'] ?></span><label>Tirs</label><span id="away-shots"><?= (int)$stats['away']['shots'] ?></span></div>
                    <div><span id="home-on-target"><?= (int)$stats['home']['on_target'] ?></span><label>Tirs cadrés</label><span id="away-on-target"><?= (int)$stats['away']['on_target'] ?></span></div>
                    <div><span id="home-passes"><?= (int)$stats['home']['passes_completed'] ?>/<?= (int)$stats['home']['passes'] ?></span><label>Passes réussies</label><span id="away-passes"><?= (int)$stats['away']['passes_completed'] ?>/<?= (int)$stats['away']['passes'] ?></span></div>
                    <div><span id="home-crosses"><?= (int)$stats['home']['crosses'] ?></span><label>Centres</label><span id="away-crosses"><?= (int)$stats['away']['crosses'] ?></span></div>
                    <div><span id="home-corners"><?= (int)$stats['home']['corners'] ?></span><label>Corners</label><span id="away-corners"><?= (int)$stats['away']['corners'] ?></span></div>
                    <div><span id="home-interceptions"><?= (int)$stats['home']['interceptions'] ?></span><label>Interceptions</label><span id="away-interceptions"><?= (int)$stats['away']['interceptions'] ?></span></div>
                    <div><span id="home-fouls"><?= (int)$stats['home']['fouls'] ?></span><label>Fautes</label><span id="away-fouls"><?= (int)$stats['away']['fouls'] ?></span></div>
                    <div><span id="home-yellow"><?= (int)$stats['home']['yellow'] ?></span><label>Cartons jaunes</label><span id="away-yellow"><?= (int)$stats['away']['yellow'] ?></span></div>
                    <div><span id="home-red"><?= (int)$stats['home']['red'] ?></span><label>Cartons rouges</label><span id="away-red"><?= (int)$stats['away']['red'] ?></span></div>
                    <div><span id="home-fatigue"><?= (float)($initialPayload['sides']['home']['fatigue'] ?? 0) ?>%</span><label>Fatigue</label><span id="away-fatigue"><?= (float)($initialPayload['sides']['away']['fatigue'] ?? 0) ?>%</span></div>
                    <div><span id="home-substitutions"><?= (int)($initialPayload['sides']['home']['substitutions'] ?? 0) ?>/5</span><label>Remplacements</label><span id="away-substitutions"><?= (int)($initialPayload['sides']['away']['substitutions'] ?? 0) ?>/5</span></div>
                </div>
            </section>

            <?php if ($userSide): ?><section class="panel"><div class="panel__header"><h2>Notes des joueurs</h2><span>Minutes · note · EXP</span></div><div class="panel__body player-ratings" id="match-ratings"><?php foreach ($ratingPlayers as $player): ?><div><span><?= e($player['name']) ?><?php if ($player['minutes'] !== null): ?> <small><?= (int)$player['minutes'] ?>′</small><?php endif; ?></span><?php if ((int)$player['experience'] > 0): ?><em>+<?= (int)$player['experience'] ?> EXP</em><?php endif; ?><strong data-rating-player="<?= (int)$player['id'] ?>"><?= number_format((float)$player['rating'], 1, ',', '') ?></strong></div><?php endforeach; ?></div></section><?php endif; ?>

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
                                <label class="field"><span>Largeur</span><select name="width"><?php foreach (['narrow' => 'Faible', 'normal' => 'Normale', 'wide' => 'Large'] as $option => $label): ?><option value="<?= $option ?>" <?= $tactic['width'] === $option ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                            </div>
                            <button class="btn btn--primary" id="tactic-submit">Appliquer en direct</button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
        </aside>
    </div>
    <script id="match-initial-data" type="application/json"><?= json_encode($initialPayload, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</div>

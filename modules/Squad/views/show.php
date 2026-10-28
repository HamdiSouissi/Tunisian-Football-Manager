<?php
$labels = [
    'passing' => 'Passe', 'dribbling' => 'Dribble', 'shooting' => 'Tir', 'first_touch' => 'Contrôle',
    'tackling' => 'Tacle', 'vision' => 'Vision', 'composure' => 'Sang-froid', 'work_rate' => 'Travail',
    'positioning' => 'Placement', 'pace' => 'Vitesse', 'stamina' => 'Endurance', 'strength' => 'Force',
    'jumping' => 'Détente', 'reflexes' => 'Réflexes', 'handling' => 'Prises de balle',
    'aerial_reach' => 'Jeu aérien', 'command_of_area' => 'Surface', 'kicking' => 'Dégagements',
];
$roleLabels = ['key_player' => 'Joueur cadre', 'regular' => 'Rotation', 'prospect' => 'Espoir'];
$footLabels = ['left' => 'gauche', 'right' => 'droit', 'both' => 'les deux'];
$secondaryPositions = json_decode((string)($player['secondary_positions'] ?? '[]'), true) ?: [];
?>
<div class="player-hero">
    <div class="player-hero__identity">
        <?= player_portrait($player, 'xl') ?>
        <div>
            <span class="eyebrow player-hero__club">
                <?php if (!empty($player['club_slug'])): ?><?= club_logo($player['club_slug'], 'club-logo') ?><?php endif; ?>
                <?= e($player['club_name'] ?? 'Joueur libre') ?>
            </span>
            <h1><?= e($player['first_name'] . ' ' . $player['last_name']) ?></h1>
            <p>
                <span class="position-badge"><?= e($player['primary_position']) ?></span>
                <?php if ($secondaryPositions): ?><span class="muted">+ <?= e(implode(', ', $secondaryPositions)) ?></span> · <?php endif; ?>
                <?= player_age($player['birth_date'], $save['current_date']) ?> ans · <?= e($player['nationality']) ?> ·
                Pied <?= e($footLabels[$player['preferred_foot']] ?? $player['preferred_foot']) ?>
            </p>
            <small>Né le <?= format_date($player['birth_date']) ?> · <?= (int)$player['height_cm'] ?> cm · <?= (int)$player['weight_kg'] ?> kg</small>
        </div>
    </div>
    <div class="player-hero__ratings">
        <div><strong><?= (int)$player['overall_rating'] ?></strong><span>OVR</span></div>
        <div><strong><?= (int)$player['potential'] ?></strong><span>POT</span></div>
        <div><strong><?= (int)($player['experience'] ?? 0) ?></strong><span>EXP</span></div>
    </div>
</div>

<div class="player-grid">
    <section class="panel">
        <div class="panel__header"><h2>Profil des attributs</h2><span>Évaluation sur 100</span></div>
        <div class="panel__body radar-wrap"><canvas id="player-radar" width="420" height="360" data-radar="<?= e(json_encode($radar, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></canvas></div>
    </section>

    <section class="panel">
        <div class="panel__header"><h2>État et contrat</h2></div>
        <div class="panel__body">
            <dl class="facts">
                <div><dt>Valeur marchande</dt><dd><?= format_money($player['market_value']) ?></dd></div>
                <div><dt>Salaire / semaine</dt><dd><?= format_money($player['contract_salary'] ?? 0) ?></dd></div>
                <div><dt>Contrat</dt><dd><?= $player['contract_starts'] ? format_date($player['contract_starts'], 'd/m/Y') . ' → ' . format_date($player['contract_ends']) : 'Joueur libre' ?></dd></div>
                <div><dt>Rôle dans l’effectif</dt><dd><?= e($roleLabels[$player['squad_role'] ?? ''] ?? 'Libre') ?></dd></div>
                <div><dt>Clause libératoire</dt><dd><?= !empty($player['release_clause']) ? format_money($player['release_clause']) : 'Aucune' ?></dd></div>
            </dl>
            <div class="status-bars">
                <label>Forme <b><?= (int)$player['form'] ?>%</b><div class="progress"><span style="width:<?= max(0, min(100, (int)$player['form'])) ?>%"></span></div></label>
                <label>Moral <b><?= (int)$player['morale'] ?>%</b><div class="progress"><span style="width:<?= max(0, min(100, (int)$player['morale'])) ?>%"></span></div></label>
                <label>Fatigue <b><?= (int)$player['fatigue'] ?>%</b><div class="progress progress--danger"><span style="width:<?= max(0, min(100, (int)$player['fatigue'])) ?>%"></span></div></label>
            </div>
            <?php if ($player['injury_type']): ?><div class="alert alert--danger"><strong><?= e($player['injury_type']) ?></strong><br>Retour estimé : <?= format_date($player['injury_until']) ?></div><?php endif; ?>
        </div>
    </section>

    <section class="panel panel--wide">
        <div class="panel__header"><h2>Statistiques de carrière</h2><span>Événements enregistrés dans cette sauvegarde</span></div>
        <div class="panel__body career-metrics">
            <?php foreach (['goals' => 'Buts', 'assists' => 'Passes décisives', 'yellow_cards' => 'Cartons jaunes', 'red_cards' => 'Cartons rouges', 'injuries' => 'Blessures en match'] as $key => $label): ?>
                <div><strong><?= (int)($career[$key] ?? 0) ?></strong><span><?= e($label) ?></span></div>
            <?php endforeach; ?>
        </div>
      </section>

    <section class="panel panel--wide">
        <div class="panel__header"><h2>Attributs détaillés</h2><span>Technique · mental · physique · gardien</span></div>
        <div class="panel__body attribute-grid">
            <?php foreach ($attributes as $attribute): ?>
                <div><span><?= e($labels[$attribute]) ?></span><b class="rating rating--<?= $player[$attribute] >= 75 ? 'high' : ($player[$attribute] >= 60 ? 'mid' : 'low') ?>"><?= (int)$player[$attribute] ?></b><div class="mini-bar"><span style="width:<?= max(0, min(100, (int)$player[$attribute])) ?>%"></span></div></div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel__header"><h2>Progression récente</h2><a href="/saves/<?= (int)$context->saveId() ?>/training">Entraînement</a></div>
        <div class="panel__body">
            <?php if (!$progress): ?><p class="muted">Aucun rapport pour le moment. La première semaine d’entraînement alimentera cette chronologie.</p><?php endif; ?>
            <div class="timeline"><?php foreach ($progress as $entry): ?><div><time><?= format_date($entry['week_start'], 'd/m/Y') ?></time><span><?= e($entry['attribute'] ? $labels[$entry['attribute']] ?? $entry['attribute'] : 'Récupération') ?><?php if ($entry['attribute'] && $entry['new_value'] !== null): ?> : <strong><?= (int)$entry['previous_value'] ?> → <?= (int)$entry['new_value'] ?></strong><?php endif; ?></span></div><?php endforeach; ?></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel__header"><h2>Historique des transferts</h2><a href="/saves/<?= (int)$context->saveId() ?>/transfers">Mercato</a></div>
        <div class="panel__body">
            <?php if (!$history): ?><p class="muted">Aucun transfert enregistré.</p><?php endif; ?>
            <div class="timeline"><?php foreach ($history as $transfer): ?><div><time><?= format_date($transfer['transfer_date'], 'd/m/Y') ?></time><span><?= e($transfer['from_name'] ?? 'Libre') ?> → <?= e($transfer['to_name'] ?? 'Libre') ?> · <strong><?= format_money($transfer['fee']) ?></strong></span></div><?php endforeach; ?></div>
        </div>
    </section>
</div>

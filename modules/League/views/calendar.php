<?php
$base = '/saves/' . (int)$context->saveId();
$currentDate = (string)$save['current_date'];
$calendarUrl = static fn (string $view, string $date): string => $base . '/calendar?' . http_build_query(['league'=>(int)$league['id'], 'view'=>$view, 'date'=>$date]);
$next = $nextFixture;
$nextIsHome = $next && (int)$next['home_club_id'] === $context->clubId();
$nextOpponent = $next ? ($nextIsHome ? $next['away_name'] : $next['home_name']) : null;
$nextCanPlay = $next && (in_array($next['status'], ['live','halftime'], true) || substr((string)$next['scheduled_at'], 0, 10) <= $currentDate);
$statusLabels = ['scheduled'=>'Programmé', 'live'=>'En direct', 'halftime'=>'Mi-temps', 'finished'=>'Terminé'];
?>
<div class="page-heading">
    <div><span class="eyebrow">Saison aller-retour</span><h1>Calendrier & résultats</h1><p><?= e($league['name']) ?> · date du monde : <?= format_date($currentDate, 'd/m/Y') ?></p></div>
    <?php if ($next): ?><form method="post" action="<?= $base ?>/calendar/next-match"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="btn btn--primary" type="submit">Aller au prochain match</button></form><?php endif; ?>
</div>

<div class="calendar-layout">
    <div class="calendar-main">
        <section class="panel calendar-toolbar">
            <div class="panel__body">
                <form method="get" class="filter-form filter-form--wrap">
                    <input type="hidden" name="view" value="<?= e($viewMode) ?>"><input type="hidden" name="date" value="<?= e($anchorDate) ?>">
                    <label>Division<select name="league" onchange="this.form.submit()"><?php foreach ($leagues as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)$row['id']===(int)$league['id']?'selected':'' ?>><?= e($row['short_name']) ?></option><?php endforeach; ?></select></label>
                    <?php if ($viewMode === 'list'): ?><label>Journée<select name="matchday" onchange="this.form.submit()"><option value="0">Toutes</option><?php for ($i=1;$i<=30;$i++): ?><option value="<?= $i ?>" <?= $selectedMatchday===$i?'selected':'' ?>>J<?= $i ?></option><?php endfor; ?></select></label><?php endif; ?>
                    <noscript><button class="btn btn--sm">Filtrer</button></noscript>
                </form>
                <nav class="calendar-views" aria-label="Type de calendrier">
                    <?php foreach (['month'=>'Mois','week'=>'Semaine','list'=>'Liste'] as $mode=>$label): ?><a class="<?= $viewMode===$mode?'active':'' ?>" href="<?= e($calendarUrl($mode, $anchorDate)) ?>" <?= $viewMode===$mode?'aria-current="page"':'' ?>><?= $label ?></a><?php endforeach; ?>
                </nav>
            </div>
        </section>

        <?php if ($calendarView): ?>
            <section class="panel calendar-period">
                <div class="panel__header"><a class="icon-button" href="<?= e($calendarUrl($viewMode, $calendarView['previous'])) ?>" aria-label="Période précédente">←</a><h2><?= e(ucfirst($calendarView['label'])) ?></h2><a class="icon-button" href="<?= e($calendarUrl($viewMode, $calendarView['next'])) ?>" aria-label="Période suivante">→</a></div>
                <a class="calendar-today" href="<?= e($calendarUrl($viewMode, $currentDate)) ?>">Revenir à la date actuelle</a>
                <?php if ($viewMode === 'month'): ?>
                    <div class="calendar-month">
                        <?php foreach ($calendarView['days'] as $day): ?><div class="calendar-weekday"><?= e(substr($day, 0, 3)) ?></div><?php endforeach; ?>
                        <?php foreach ($calendarView['cells'] as $cell): ?>
                            <div class="calendar-day <?= !$cell['in_period']?'is-outside':'' ?> <?= $cell['is_today']?'is-today':'' ?>">
                                <time datetime="<?= $cell['date'] ?>"><?= (int)$cell['day'] ?></time>
                                <div class="calendar-day__events">
                                    <?php foreach (array_slice($cell['fixtures'], 0, 3) as $fixture): $own=$fixture['is_own']; $score=$fixture['status']==='finished'?(int)$fixture['home_score'].'–'.(int)$fixture['away_score']:'J'.(int)$fixture['matchday']; ?>
                                        <?php if ($own): ?><a class="calendar-fixture is-own" href="<?= $base ?>/matches/<?= (int)$fixture['id'] ?>"><strong><?= e($fixture['home_short']) ?> <span><?= $score ?></span> <?= e($fixture['away_short']) ?></strong></a><?php else: ?><div class="calendar-fixture"><strong><?= e($fixture['home_short']) ?> <span><?= $score ?></span> <?= e($fixture['away_short']) ?></strong></div><?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if (count($cell['fixtures']) > 3): ?><a class="calendar-more" href="<?= e($calendarUrl('week', $cell['date'])) ?>">+<?= count($cell['fixtures'])-3 ?> match(s)</a><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="calendar-week">
                        <?php foreach ($calendarView['cells'] as $cell): ?>
                            <section class="calendar-week__day <?= $cell['is_today']?'is-today':'' ?>">
                                <header><span><?= e($cell['weekday']) ?></span><time datetime="<?= $cell['date'] ?>"><?= format_date($cell['date'], 'd/m') ?></time></header>
                                <div><?php foreach ($cell['fixtures'] as $fixture): ?>
                                    <article class="week-fixture <?= $fixture['is_own']?'is-own':'' ?>">
                                        <small><?= format_date($fixture['scheduled_at'], 'H:i') ?> · J<?= (int)$fixture['matchday'] ?></small>
                                        <strong><?= e($fixture['home_short']) ?> <?= $fixture['status']==='finished'?(int)$fixture['home_score'].'–'.(int)$fixture['away_score']:'–' ?> <?= e($fixture['away_short']) ?></strong>
                                        <?php if ($fixture['is_own']): ?><a href="<?= $base ?>/matches/<?= (int)$fixture['id'] ?>"><?= $fixture['status']==='finished'?'Rapport':'Ouvrir' ?> →</a><?php endif; ?>
                                    </article>
                                <?php endforeach; ?><?php if (!$cell['fixtures']): ?><p class="muted">Aucun match</p><?php endif; ?></div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <section class="panel"><div class="table-wrap"><table class="table"><thead><tr><th>J.</th><th>Date</th><th>Domicile</th><th>Score</th><th>Extérieur</th><th>Statut</th><th></th></tr></thead><tbody>
                <?php foreach ($fixtures as $fixture): $own=in_array($context->clubId(), [(int)$fixture['home_club_id'],(int)$fixture['away_club_id']], true); ?>
                    <tr class="<?= $own?'is-own':'' ?>"><td>J<?= (int)$fixture['matchday'] ?></td><td><?= format_date($fixture['scheduled_at'], 'd/m/Y H:i') ?></td><td><span class="table-club"><?= club_logo($fixture['home_slug']) ?><span><?= e($fixture['home_name']) ?></span></span></td><td><strong><?= $fixture['status']==='scheduled'?'—':(int)$fixture['home_score'].' - '.(int)$fixture['away_score'] ?></strong></td><td><span class="table-club"><?= club_logo($fixture['away_slug']) ?><span><?= e($fixture['away_name']) ?></span></span></td><td><span class="badge <?= $fixture['status']==='finished'?'badge--success':($fixture['status']==='live'?'badge--danger':'') ?>"><?= e($statusLabels[$fixture['status']] ?? $fixture['status']) ?></span></td><td><?php if ($own): ?><a class="text-link" href="<?= $base ?>/matches/<?= (int)$fixture['id'] ?>"><?= $fixture['status']==='finished'?'Rapport':'Match' ?></a><?php endif; ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$fixtures): ?><tr><td colspan="7"><div class="empty-state">Aucune rencontre pour ce filtre.</div></td></tr><?php endif; ?>
            </tbody></table></div></section>
        <?php endif; ?>
    </div>

    <aside class="calendar-sidebar">
        <?php if ($next): ?>
            <section class="panel next-match-card">
                <div class="panel__header"><h2>Prochain match</h2><span class="badge"><?= $nextIsHome?'Domicile':'Extérieur' ?></span></div>
                <div class="panel__body">
                    <span class="eyebrow">J<?= (int)$next['matchday'] ?> · <?= format_date($next['scheduled_at'], 'd/m/Y H:i') ?></span>
                    <div class="next-match-card__clubs"><div><?= club_logo($next['home_slug'], 'club-logo') ?><strong><?= e($next['home_short']) ?></strong></div><span>–</span><div><?= club_logo($next['away_slug'], 'club-logo') ?><strong><?= e($next['away_short']) ?></strong></div></div>
                    <p>Adversaire : <strong><?= e($nextOpponent) ?></strong></p>
                    <div class="next-match-card__links"><a href="<?= $base ?>/tactics">Préparation</a><a href="<?= $base ?>/tactics#tactics-form">Composition</a><a href="#calendar-stats">Statistiques</a></div>
                    <?php if ($next['status'] === 'scheduled' && $nextCanPlay): ?><form method="post" action="<?= $base ?>/matches/<?= (int)$next['id'] ?>/start"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><button class="btn btn--primary btn--block" type="submit">Jouer le match</button></form><?php endif; ?>
                    <a class="btn <?= $nextCanPlay?'btn--ghost':'btn--primary' ?> btn--block" href="<?= $base ?>/matches/<?= (int)$next['id'] ?>"><?= $next['status']==='scheduled'?($nextCanPlay?'Préparer le match':'Ouvrir l’avant-match'):'Reprendre le direct' ?></a>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel__header"><h2>Avancer dans le temps</h2></div>
            <div class="panel__body"><form method="post" action="<?= $base ?>/calendar/advance" class="form-stack"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><label class="field"><span>Simuler jusqu’à cette date</span><input type="date" name="target_date" required min="<?= $currentDate ?>" max="<?= (new DateTimeImmutable($currentDate))->modify('+120 days')->format('Y-m-d') ?>" value="<?= $currentDate ?>"></label><small>120 jours maximum. La simulation s’arrête avant votre prochain match et met à jour tout le monde.</small><button class="btn btn--ghost" type="submit">Simuler jusqu’à cette date</button></form></div>
        </section>

        <section class="panel" id="calendar-stats">
            <div class="panel__header"><h2>Forme récente</h2><span>5 derniers matchs</span></div>
            <div class="panel__body recent-results">
                <?php foreach ($recentResults as $result): $home=(int)$result['home_club_id']===$context->clubId(); $ownScore=(int)($home?$result['home_score']:$result['away_score']); $otherScore=(int)($home?$result['away_score']:$result['home_score']); $outcome=$ownScore>$otherScore?'V':($ownScore===$otherScore?'N':'D'); ?>
                    <a href="<?= $base ?>/matches/<?= (int)$result['id'] ?>"><span class="result-dot result-dot--<?= strtolower($outcome) ?>"><?= $outcome ?></span><div><strong><?= e($home?$result['away_short']:$result['home_short']) ?></strong><small><?= format_date($result['scheduled_at'], 'd/m/Y') ?> · <?= $home?'Domicile':'Extérieur' ?></small></div><b><?= $ownScore ?>–<?= $otherScore ?></b></a>
                <?php endforeach; ?>
                <?php if (!$recentResults): ?><p class="muted">Aucun résultat disponible pour le moment.</p><?php endif; ?>
            </div>
        </section>
    </aside>
</div>

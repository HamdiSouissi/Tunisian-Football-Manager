<?php
$focuses=['physical'=>'Physique','technical'=>'Technique','tactical'=>'Tactique','defense'=>'Défense','attack'=>'Attaque','goalkeeping'=>'Gardiens','recovery'=>'Récupération'];
$labels=['passing'=>'Passe','dribbling'=>'Dribble','shooting'=>'Tir','first_touch'=>'Contrôle','tackling'=>'Tacle','vision'=>'Vision','composure'=>'Sang-froid','work_rate'=>'Travail','positioning'=>'Placement','pace'=>'Vitesse','stamina'=>'Endurance','strength'=>'Force','jumping'=>'Détente','reflexes'=>'Réflexes','handling'=>'Prises de balle','aerial_reach'=>'Jeu aérien','command_of_area'=>'Surface','kicking'=>'Dégagements'];
$individual=array_column($plan['individual'],'target_attribute','player_id');
?>
<div class="page-heading"><div><span class="eyebrow">Semaine du <?= format_date($plan['week_start']) ?></span><h1>Entraînement</h1><p>Le programme est appliqué chaque lundi par le bouton Continuer.</p></div><?php if($plan['report']): ?><span class="badge badge--success">Semaine déjà appliquée</span><?php endif; ?></div>
<form method="post" action="/saves/<?= (int)$context->saveId() ?>/training" id="training-form">
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
    <div class="training-layout">
        <section class="panel">
            <div class="panel__header"><h2>Programme collectif</h2></div>
            <div class="panel__body">
                <div class="training-types"><?php foreach($focuses as $key=>$label): ?><label><input type="radio" name="focus" value="<?= $key ?>" <?= $plan['focus']===$key?'checked':'' ?>><span><i><?= ['physical'=>'♥','technical'=>'⚽','tactical'=>'▧','defense'=>'◉','attack'=>'↗','goalkeeping'=>'♜','recovery'=>'＋'][$key] ?></i><strong><?= $label ?></strong><small><?= $key==='recovery'?'Fatigue -18':'Développement ciblé' ?></small></span></label><?php endforeach; ?></div>
                <label class="field"><span>Intensité</span><select name="intensity"><option value="low" <?= $plan['intensity']==='low'?'selected':'' ?>>Faible · fatigue réduite</option><option value="medium" <?= $plan['intensity']==='medium'?'selected':'' ?>>Moyenne · équilibre</option><option value="high" <?= $plan['intensity']==='high'?'selected':'' ?>>Élevée · progression et risques accrus</option></select></label>
            </div>
        </section>
        <section class="panel panel--wide">
            <div class="panel__header"><h2>Suivi individuel</h2><button class="btn btn--primary" type="submit">Enregistrer le programme</button></div>
            <div class="table-wrap"><table class="table table--compact"><thead><tr><th>Joueur</th><th>Poste</th><th>Âge</th><th>OVR / POT</th><th>Fatigue</th><th>Attribut ciblé</th></tr></thead><tbody>
                <?php foreach($players as $player): ?><tr><td><?= e($player['first_name'].' '.$player['last_name']) ?></td><td><span class="position-badge"><?= e($player['primary_position']) ?></span></td><td><?= player_age($player['birth_date'],$context->save()['current_date']) ?></td><td><?= (int)$player['overall_rating'] ?> / <?= (int)$player['potential'] ?></td><td><?= (int)$player['fatigue'] ?>%</td><td><select name="individual[<?= (int)$player['id'] ?>]" form="training-form"><option value="">Programme collectif</option><?php foreach($attributes as $attribute): ?><option value="<?= $attribute ?>" <?= ($individual[$player['id']]??'')===$attribute?'selected':'' ?>><?= e($labels[$attribute]) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>
</form>
<section class="panel">
    <div class="panel__header"><h2>Dernier rapport</h2><?php if($report): ?><span><?= format_date($report['week_start']) ?> · <?= e($report['summary']) ?></span><?php endif; ?></div>
    <div class="panel__body"><?php if(!$report): ?><div class="empty-state"><p>Aucun rapport. Avancez jusqu’à lundi pour appliquer votre programme.</p></div><?php else: ?><div class="training-report"><?php foreach($report['entries'] as $entry): if(!$entry['attribute']&&!$entry['injury_occurred']&&abs((int)$entry['fatigue_delta'])<5)continue; ?><div><span class="avatar"><?= e(substr($entry['first_name'],0,1).substr($entry['last_name'],0,1)) ?></span><span><strong><?= e($entry['first_name'].' '.$entry['last_name']) ?></strong><small><?= $entry['attribute']?e($labels[$entry['attribute']]??$entry['attribute']):'Récupération / charge' ?></small></span><?php if($entry['attribute']): ?><b class="rating rating--<?= $entry['new_value']>$entry['previous_value']?'high':'low' ?>"><?= (int)$entry['previous_value'] ?> → <?= (int)$entry['new_value'] ?></b><?php endif; ?><?php if($entry['injury_occurred']): ?><span class="badge badge--danger">Blessure</span><?php endif; ?><span class="badge"><?= (int)$entry['fatigue_delta']>=0?'+':'' ?><?= (int)$entry['fatigue_delta'] ?> fatigue</span></div><?php endforeach; ?></div><?php endif; ?></div>
</section>

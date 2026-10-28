<div class="page-heading"><div><span class="eyebrow">Configuration de la carrière</span><h1>Nouvelle partie</h1><p>Le monde complet sera généré avec deux divisions et des joueurs fictifs.</p></div></div>
<form method="post" action="/saves" class="form-layout" data-save-form>
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
    <section class="panel"><div class="panel__header"><h2>1. Votre profil</h2></div><div class="panel__body form-grid">
        <label class="field"><span>Nom de la sauvegarde</span><input name="name" value="Ma carrière tunisienne" maxlength="120" required></label>
        <label class="field"><span>Nom de l’entraîneur</span><input name="manager_name" value="<?= e($user['name'] ?? '') ?>" maxlength="120" required></label>
        <label class="field"><span>Difficulté</span><select name="difficulty"><?php foreach ($difficulties as $key => $difficulty): ?><option value="<?= e($key) ?>" <?= $key === 'normal' ? 'selected' : '' ?>><?= e($difficulty['label']) ?></option><?php endforeach; ?></select></label>
    </div></section>
    <section class="panel"><div class="panel__header"><h2>2. Votre club</h2></div><div class="panel__body">
        <label class="field field--compact"><span>Division</span><select name="division" id="save-division"><option value="1">Ligue Professionnelle 1</option><option value="2">Ligue Professionnelle 2</option></select></label>
        <div class="club-picker">
        <?php foreach ($leagues as $league): foreach ($league['clubs'] as $club): ?>
            <label class="club-option" data-division="<?= (int) $league['level'] ?>" style="--club-color: <?= e($club['primary']) ?>"><input type="radio" name="club_slug" value="<?= e($club['slug']) ?>" required><span class="club-option__crest"><?= club_logo($club['slug'], 'club-logo', false) ?></span><span><strong><?= e($club['name']) ?></strong><small><?= e($club['city']) ?> · <?= e($club['stadium']) ?></small></span><span class="rating"><?= (int) $club['reputation'] ?></span></label>
        <?php endforeach; endforeach; ?>
        </div>
    </div></section>
    <div class="form-actions"><a class="btn btn--ghost" href="/saves">Annuler</a><button class="btn btn--primary" data-submit-save>Générer le monde et commencer</button></div>
</form>

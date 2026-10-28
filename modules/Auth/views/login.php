<?php use App\Session\Session; $oldEmail = app(Session::class)->pullFlash('old_email', ''); ?>
<section class="auth-card">
    <div class="auth-card__intro"><span class="eyebrow">Votre carrière vous attend</span><h1>De retour sur le banc ?</h1><p>Retrouvez vos clubs, vos tactiques et vos sauvegardes.</p></div>
    <form method="post" action="/login" class="form-stack">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <label class="field"><span>Adresse e-mail</span><input type="email" name="email" value="<?= e($oldEmail) ?>" autocomplete="email" required autofocus></label>
        <label class="field"><span>Mot de passe</span><input type="password" name="password" autocomplete="current-password" required></label>
        <button class="btn btn--primary btn--block">Se connecter</button>
    </form>
    <p class="auth-switch">Pas encore de compte ? <a href="/register">Créer un compte</a></p>
</section>

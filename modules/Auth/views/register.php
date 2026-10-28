<section class="auth-card">
    <div class="auth-card__intro"><span class="eyebrow">Nouvelle carrière</span><h1>Créez votre compte</h1><p>Un compte vous permet de gérer plusieurs carrières indépendantes.</p></div>
    <form method="post" action="/register" class="form-stack">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <label class="field"><span>Nom</span><input name="name" value="<?= e($old['name'] ?? '') ?>" maxlength="100" autocomplete="name" required autofocus></label>
        <label class="field"><span>Adresse e-mail</span><input type="email" name="email" value="<?= e($old['email'] ?? '') ?>" maxlength="191" autocomplete="email" required></label>
        <div class="form-grid">
            <label class="field"><span>Mot de passe</span><input type="password" name="password" minlength="10" autocomplete="new-password" required><small>10 caractères minimum</small></label>
            <label class="field"><span>Confirmation</span><input type="password" name="password_confirmation" minlength="10" autocomplete="new-password" required></label>
        </div>
        <button class="btn btn--primary btn--block">Créer mon compte</button>
    </form>
    <p class="auth-switch">Déjà inscrit ? <a href="/login">Se connecter</a></p>
</section>

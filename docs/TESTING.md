# Validation et tests restants

Date de la dernière série automatisée : **28 octobre 2026**.

Ce document distingue les vérifications effectivement exécutées des parcours qui nécessitent encore une installation MySQL/MariaDB et un navigateur. Une fonctionnalité présente dans le code n’est pas déclarée « testée de bout en bout » tant que son parcours persistant n’a pas été exécuté.

## Vérifications exécutées

Environnement de livraison : PHP 8.1 WebAssembly temporaire pour l’exécution des scripts, Node.js pour la syntaxe JavaScript. Aucune dépendance de test n’est ajoutée au projet.

| Vérification | Résultat |
|---|---|
| Analyse syntaxique PHP avec `TOKEN_PARSE` | **126 fichiers, 0 erreur** |
| `tests/smoke.php` | **Smoke tests : OK** |
| `tests/engine.php` | **12 exécutions consécutives : Tests moteur, calendrier et IA : OK** |
| `node --check` sur les cinq fichiers JS | **OK** |
| Équilibre des accolades CSS | **OK** |
| `git diff --check` | **OK** |

### Couverture des smoke tests

- démarrage de l’application et découverte des modules ;
- autowiring de la boucle, de la fin de saison, des services et des contrôleurs ;
- bindings `MatchEngineInterface` et `AIManagerInterface` ;
- dispatcher, motifs génériques et priorités ;
- découpage des **27 instructions** de migration ;
- CSRF par en-tête et rejet d’un jeton invalide ;
- route `/health`, contraintes de paramètres et 404 ;
- pages invitées `/login` et `/register`, redirections de `/` et `/saves` ;
- blocage CSRF d’un POST de connexion avant toute connexion PDO ;
- catalogue de 2 ligues, 32 clubs et 12 joueurs libres.

### Couverture du moteur et du calendrier

- initialisation de deux équipes et calcul des forces ;
- impact mesuré des rôles/consignes sur l’attaque et le milieu ;
- cinq minutes puis remplacement, entrée/sortie et compteur ;
- changement de mentalité et pressing en direct ;
- déroulement jusqu’à 90 minutes avec mi-temps à 45 ;
- possession cumulée sur 90 minutes ;
- égalité entre les buts du score et les événements de but ;
- 240 rencontres pour une division de 16 clubs, 30 matchs par club, aucune confrontation dupliquée dans une phase et aucun match contre soi-même ;
- acceptation/refus/contre-offre de l’IA et ouverture des fenêtres d’été/hiver.

## Non exécutés dans cet environnement

Aucun serveur MySQL/MariaDB ou navigateur n’était disponible. En conséquence, les éléments suivants ne sont **pas déclarés validés de bout en bout** :

- exécution réelle des cinq migrations et contraintes FK/index sur MySQL et MariaDB ;
- inscription/connexion avec écriture PDO ;
- génération transactionnelle complète d’une sauvegarde ;
- affichage authentifié de toutes les pages avec données réelles ;
- avancement journalier/hebdomadaire, verrous et reprises SQL ;
- direct persistant avec requêtes fetch et rechargement ;
- application SQL des entraînements, blessures, transferts, finances et fin de saison ;
- affichage responsive et interactions glisser-déposer dans un navigateur.

Le runtime WebAssembly sert uniquement aux tests sans base ; il n’est pas un serveur d’application recommandé et n’est pas inclus dans le dépôt.

## Matrice de validation sur une installation réelle

### 1. Installation et identité

1. Créer une base vierge et exécuter `php bin/migrate` puis `php bin/migrate status`.
2. Vérifier que les cinq migrations sont enregistrées et que les tables/index/FK existent.
3. Créer un compte, se déconnecter, se reconnecter ; tester un mot de passe erroné et un POST sans CSRF.
4. Vérifier qu’un utilisateur ne peut pas ouvrir la sauvegarde d’un autre utilisateur.

### 2. Seed et pages principales

1. Créer une carrière en Ligue 1 puis une autre en Ligue 2.
2. Pour chaque `save_id`, contrôler : 2 ligues, 32 clubs, 716 joueurs, 480 fixtures, 32 tactiques et 32 lignes de standings.
3. Ouvrir dashboard, effectif, fiche joueur, tactique, calendrier, classement, entraînement, transferts, finances, club, staff et messagerie.
4. Vérifier l’absence de warning PHP, de requête sans résultat inattendu et de contenu non échappé.

### 3. Tactique et match

1. Permuter deux titulaires, déplacer un remplaçant, changer formation, rôle, consigne et style ; enregistrer puis recharger.
2. À la date du match, démarrer le direct, jouer jusqu’à la mi-temps, effectuer un remplacement et modifier les consignes.
3. Reprendre, recharger la page au cours du match et terminer les 90 minutes.
4. Contrôler score/événements/statistiques, classement, fatigue, moral, billetterie et message de résultat.
5. Vérifier qu’un match terminé ne peut pas être finalisé une seconde fois et que Continuer est bloqué pendant un direct.

### 4. Boucle, entraînement et finances

1. Avancer jusqu’à lundi : vérifier un seul rapport par club/semaine et `applied_at` renseigné.
2. Contrôler progression, OVR recalculé, fatigue, blessures et retour à la date prévue.
3. Vérifier salaires hebdomadaires, sponsors, billetterie des matchs à domicile et droits TV aux échéances.
4. Vérifier les variations de confiance après victoire/nul/défaite et le statut de carrière lorsque la confiance atteint zéro.

### 5. Mercato

Tester séparément pendant une fenêtre ouverte :

- achat accepté, refusé puis contre-offre ;
- vente à une IA et réception d’une offre pour un joueur du club dirigé ;
- salaire refusé par le joueur ;
- recrutement d’un joueur libre avec prime à la signature ;
- prêt, part salariale enregistrée, option et retour à l’échéance ;
- shortlist, historique et expiration des offres ;
- impossibilité de conclure hors fenêtre ou au-delà du budget.

Contrôler après chaque opération le club du joueur, les contrats, les deux écritures financières concernées et le budget transferts.

### 6. Fin de saison

Sur une sauvegarde de test, avancer jusqu’au 1er juin puis vérifier :

- ancienne saison `finished`, nouvelle saison active au 1er juillet ;
- deux relégués et deux promus ;
- nouveaux standings et 480 fixtures sans doublon ;
- contrats expirés et joueurs devenus libres, retraites, deux jeunes par club ;
- objectifs évalués puis nouveaux objectifs et budgets ;
- tactiques encore composées de onze joueurs appartenant au bon club.

### 7. Navigateur

Valider Chromium et, si possible, Firefox : desktop 1440 px, tablette 1024/768 px et mobile 390 px. Tester menu latéral, onglets mercato, tri/recherche, radar, glisser-déposer et alternative au clic, lecture automatique, formulaires et absence d’erreur console.

Consigner dans ce document la version de PHP, MySQL/MariaDB et du navigateur ainsi que les résultats obtenus après chaque campagne.

# Validation et tests restants

Date de la dernière série automatisée : **28 octobre 2026**.

Ce document distingue les vérifications effectivement exécutées des parcours qui nécessitent encore une installation MySQL/MariaDB et un navigateur. Une fonctionnalité présente dans le code n’est pas déclarée « testée de bout en bout » tant que son parcours persistant n’a pas été exécuté.

## Vérifications exécutées

Environnement de livraison : PHP 8.1 WebAssembly temporaire pour l’exécution des scripts, Node.js pour la syntaxe JavaScript. Aucune dépendance de test n’est ajoutée au projet.

| Vérification | Résultat |
|---|---|
| Analyse syntaxique PHP avec `TOKEN_PARSE` | **137 fichiers, 0 erreur** |
| `tests/smoke.php` | **Smoke tests : OK** |
| `tests/engine.php` | **Tests moteur, calendrier et IA : OK** |
| `tests/sqlite_workflows.php` | **Workflows PDO : jour de match, rapport, calendrier, expérience et persistance OK** |
| `tests/match_client.cjs` | **Frames compactes, score, possesseur, numéros et reprise Canvas : OK** |
| Assets des clubs | **32/32 slugs couverts, 32 PNG valides en 256 × 256** |
| Portraits générés | **Déterminisme, variante et échappement testés ; six variantes converties en PNG et inspectées** |
| `node --check` sur les cinq fichiers JS | **OK** |
| Équilibre des accolades CSS | **OK** |
| `git diff --check` | **OK** |

### Couverture des smoke tests

- démarrage de l’application et découverte des modules ;
- autowiring de la boucle, de la fin de saison, des services et des contrôleurs ;
- bindings `MatchEngineInterface` et `AIManagerInterface` ;
- dispatcher, motifs génériques et priorités ;
- découpage des **28 instructions** de migration ;
- régression MySQL : `` `current_date` `` reste échappé dans les quatre requêtes de création/lecture/avancement/rollover ;
- avancement d’une date avec changement de mois et année bissextile, sans l’appel inexistant `DateTimeImmutable::addDay()` ;
- récupération de fatigue avec une soustraction explicitement signée avant application de `GREATEST`, pour éviter le dépassement MySQL `22003` sur une colonne `UNSIGNED` ;
- détection des paramètres nommés dupliqués dans les littéraux SQL, incompatibles avec les requêtes PDO natives ;
- détail `PDOException` conservé avec `APP_DEBUG=true`, mais masqué avec `APP_DEBUG=false` ;
- rendu de la vue Finances sans aucune transaction puis avec des flux mensuels positifs et négatifs ;
- portraits SVG déterministes, distincts selon le joueur, accessibles et échappés face aux données HTML invalides ;
- rendu complet de la fiche joueur avec portrait, contrat et statistiques de carrière ;
- CSRF par en-tête et rejet d’un jeton invalide ;
- route `/health`, contraintes de paramètres et 404 ;
- pages invitées `/login` et `/register`, redirections de `/` et `/saves` ;
- blocage CSRF d’un POST de connexion et d’un POST de simulation de match avant toute connexion PDO ;
- catalogue de 2 ligues, 32 clubs et 12 joueurs libres ;
- correspondance entre chaque slug et son blason local, URL des images et fallback pour slug invalide/asset absent.

### Couverture des workflows PDO SQLite

Le script utilise une base SQLite en mémoire, de vraies `PDOStatement` avec `ATTR_EMULATE_PREPARES=false` et un schéma minimal limité aux parcours testés. Il ne valide ni les migrations ni la syntaxe propre à MySQL.

- création d’une tactique par `INSERT`, puis second enregistrement par `UPDATE` avec les bindings réellement présents dans la requête ;
- reconstruction automatique du onze lorsqu’un titulaire est blessé, chemin également utilisé par la simulation ;
- **+1 jour** du 1er au 2 juillet avec mise à jour transactionnelle de la sauvegarde ;
- récupération quotidienne de la fatigue aux limites `0`, `5`, `7` et `8`, avec résultats attendus `0`, `0`, `0` et `1` ;
- **Continuer** sur sept jours, avec application d’un lundi d’entraînement ;
- onze lignes de rapport hebdomadaire et écritures de salaires/sponsors ;
- transfert IA avec contrat et deux écritures financières, plus une offre destinée au club dirigé pendant un avancement quotidien ;
- dates et compteurs de jours contrôlés après chaque avancement ;
- refus de simuler un match futur ou celui d’un autre club ;
- arrêt de **Continuer** sur la date du match du club, blocage d’un nouvel avancement et affichage du bouton **Jouer le match** protégé par CSRF ;
- **Jouer le match** depuis le dashboard, le calendrier et l’avant-match appelle `/start`, produit un direct `live` à 0 minute avec **Lecture** actif ; la simulation instantanée reste une action secondaire explicite ;
- redirection du contrôleur vers le rapport après simulation, match terminé après le temps additionnel, score cohérent avec les événements, classement, billetterie et message de résultat ;
- rendu du rapport Canvas et du calendrier mensuel, notes/homme du match, minutes individuelles et expérience persistée correspondant exactement aux gains du moteur ;
- reprise d’un ancien direct sans état spatial, météo ni nouvelles statistiques, puis deux appels `/step` consécutifs avec persistence JSON et dix frames par minute ;
- simulation complète sur une base dont la colonne `players.experience` a été supprimée : finalisation et gains dans le rapport conservés, sans fausse assertion de persistance SQL ;
- absence de duplication des événements et de double comptabilisation au classement lors d’un second appel, puis reprise de l’avancement le lendemain.

### Couverture du moteur et du calendrier

- initialisation de deux équipes et calcul des forces ;
- impact mesuré des rôles/consignes sur l’attaque et le milieu ;
- cinq minutes puis remplacement, entrée/sortie et compteur ;
- changement de mentalité, pressing et formation en direct avec réattribution des onze postes ;
- conservation des joueurs, de l’énergie et des cartons après un ajustement tactique ;
- payload du direct exposant la formation, le onze et le banc à jour pour une synchronisation sans rechargement ;
- déroulement jusqu’à 90 minutes plus le temps additionnel, avec mi-temps après celui de la première période ;
- possession cumulée sur toutes les minutes jouées, dix frames par minute, continuité du ballon et joueurs non expulsés ;
- durée minimale d’une passe de 40 m et vitesse maximale de 2,4 m par tick, reprise maintenue jusqu’à réception au rond central, appels premier/second poteau différenciés et cibles tactiques déterministes ;
- touches, hors-jeu avec deuxième dernier défenseur, penalty spatial, corners, duels et reprises ;
- navigation mensuelle sur 42 cellules et hebdomadaire du lundi au dimanche ;
- égalité entre les buts du score et les événements de but ;
- 240 rencontres pour une division de 16 clubs, 30 matchs par club, aucune confrontation dupliquée dans une phase et aucun match contre soi-même ;
- acceptation/refus/contre-offre de l’IA et ouverture des fenêtres d’été/hiver.

### Causes racines couvertes par cette série

- **Finances sur une nouvelle sauvegarde** : `max()` recevait un entier unique lorsque le tableau mensuel était vide ; le calcul utilise maintenant toujours un tableau de valeurs.
- **+1 jour / Continuer** : l’appel inexistant `DateTimeImmutable::addDay()` est remplacé par `modify('+1 day')`, et la détection d’un direct utilise deux paramètres PDO distincts pour le club domicile/extérieur.
- **Jour de match** : la boucle simulait auparavant la rencontre du club dirigé avant que le joueur puisse agir. Elle laisse maintenant ce match en attente, s’arrête sur sa date et exige une simulation explicite ou un direct avant de reprendre.
- **Récupération / SQLSTATE 22003** : `fatigue` est un `TINYINT UNSIGNED`. MySQL évaluait `fatigue - 7` avant `GREATEST(0, …)`, ce qui produisait une valeur intermédiaire négative pour une fatigue inférieure à 7. Le cast en `SIGNED` intervient maintenant avant la soustraction, puis le résultat est borné à zéro.
- **Enregistrement tactique / HY093** : `club_id` et `fixture_id` étaient liés à un `UPDATE` qui ne déclarait pas ces paramètres ; ils sont retirés du seul tableau de bindings de cet `UPDATE`. Le même chemin est couvert lors d’une reconstruction automatique du onze.
- **Diagnostic ultérieur** : les exceptions techniques interceptées par les contrôleurs sont journalisées dans `storage/logs/app.log`. Leur classe et leur message sont aussi visibles avec `APP_DEBUG=true`, sans exposer ces détails en production.

## Non exécutés dans cet environnement

Aucun serveur MySQL/MariaDB ou navigateur n’était disponible. En conséquence, les éléments suivants ne sont **pas déclarés validés de bout en bout** :

- exécution réelle des six migrations et contraintes FK/index sur MySQL et MariaDB ;
- inscription/connexion avec écriture PDO ;
- génération transactionnelle complète d’une sauvegarde ;
- affichage authentifié de toutes les pages avec données réelles ;
- avancement journalier/hebdomadaire sur MySQL, verrous `FOR UPDATE` et reprises SQL ;
- direct persistant avec requêtes fetch et rechargement ;
- application SQL des entraînements, blessures, transferts, finances et fin de saison ;
- affichage responsive et interactions glisser-déposer dans un navigateur.

Le runtime WebAssembly sert uniquement aux tests sans MySQL ; il n’est pas un serveur d’application recommandé et n’est pas inclus dans le dépôt. SQLite est réservé aux workflows PDO décrits ci-dessus et n’est pas un driver applicatif supporté.

## Matrice de validation sur une installation réelle

### 1. Installation et identité

1. Créer une base vierge et exécuter `php bin/migrate` puis `php bin/migrate status`.
2. Vérifier que les six migrations sont enregistrées et que les tables/index/FK existent.
3. Créer un compte, se déconnecter, se reconnecter ; tester un mot de passe erroné et un POST sans CSRF.
4. Vérifier qu’un utilisateur ne peut pas ouvrir la sauvegarde d’un autre utilisateur.

### 2. Seed et pages principales

1. Créer une carrière en Ligue 1 puis une autre en Ligue 2. Rejouer explicitement le parcours avec **Olympique de Béja** et vérifier que le seed ne produit plus l’erreur PDO 1064 sur `current_date`.
2. Pour chaque `save_id`, contrôler : 2 ligues, 32 clubs, 716 joueurs, 480 fixtures, 32 tactiques et 32 lignes de standings.
3. Ouvrir dashboard, effectif, fiche joueur, tactique, calendrier, classement, entraînement, transferts, finances, club, staff et messagerie. Ouvrir notamment **Finances avant toute opération** et vérifier les portraits de plusieurs joueurs ainsi que leurs statistiques de carrière.
4. Vérifier l’absence de warning PHP, de requête sans résultat inattendu et de contenu non échappé. Tester **+1 jour** et **Continuer**, y compris un passage de fin de mois ; en cas d’erreur, consulter `storage/logs/app.log`.

### 3. Tactique et match

1. Permuter deux titulaires, déplacer un remplaçant, changer formation, rôle, consigne et style ; enregistrer puis recharger.
2. À la date du match, démarrer le direct, jouer jusqu’à la mi-temps, effectuer un remplacement, changer de formation et modifier les consignes. Vérifier la mise à jour du onze, du banc, des postes et des commentaires **sans rechargement**, puis la conservation de la fatigue et des cartons.
3. Reprendre, recharger la page au cours du match et terminer les 90 minutes ainsi que le temps additionnel. Tester pause, ×1 à ×16, remplacement animé et **Passer le match**.
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

Valider Chromium et, si possible, Firefox : desktop 1440 px, tablette 1024/768 px et mobile 390 px. Vérifier que le document global ne défile jamais, que la coquille occupe tout le viewport et que le contenu principal, le sélecteur des 32 clubs, la sidebar et les tableaux restent accessibles par leurs défilements internes. Tester ensuite menu latéral, affichage des blasons, onglets mercato, tri/recherche, radar, glisser-déposer et alternative au clic, lecture automatique, formulaires et absence d’erreur console.

Consigner dans ce document la version de PHP, MySQL/MariaDB et du navigateur ainsi que les résultats obtenus après chaque campagne.

# Architecture

## Cycle d’une requête

1. `public/index.php` charge `bootstrap/app.php`.
2. L’autoloader PSR-4 maison enregistre `App\\` → `app/` et `Modules\\` → `modules/`.
3. `Application::boot()` charge `.env`, la configuration, PDO paresseux, les sessions, les traductions et le dispatcher.
4. Les modules sont découverts par priorité ; leurs bindings sont enregistrés avant leur méthode `boot()`.
5. La session démarre et les routes de tous les modules sont chargées.
6. Le middleware CSRF global précède `AuthMiddleware` / `SaveMiddleware`, puis le contrôleur.
7. `request.handled` est émis avant l’envoi de la `Response`.

Les contrôleurs orchestrent HTTP. Les calculs métier sont dans `app/Services`, les repositories dans `app/Models` et le moteur indépendant dans `app/Engine`.

## Modules

Un manifest `module.php` déclare :

- `name` : identifiant stable en minuscules, utilisé pour les vues et migrations ;
- `class` : implémentation de `ModuleInterface` ;
- `priority` : ordre croissant de chargement ;
- `version` et `enabled` : métadonnées de cycle de vie.

Les vues sont enregistrées sous `<module>::<vue>` : `match::show` correspond à `modules/Match/views/show.php`. Les bindings importants sont :

- `MatchEngineInterface` → `TacticalMatchEngine` dans `MatchModule` ;
- `AIManagerInterface` → `ClubAIManager` dans `TransfersModule` ;
- `ClubCatalog` et `PlayerFactory` avec leur chemin de données dans `AuthModule`.

`FinancesModule` écoute les matchs et transferts terminés. Les autres modules restent principalement composés de routes, contrôleurs et vues ; leurs services partagés sont dans `app/Services`.

## Boucle de simulation

`GameLoopService` avance d’un à sept jours. Chaque journée est transactionnelle et exécute, dans l’ordre :

1. mise à jour de la date et récupération quotidienne ;
2. simulation des rencontres prévues ce jour ;
3. entraînement si la date est un lundi ;
4. salaires/sponsors/droits TV selon l’échéance ;
5. retours de prêt, expiration des offres et actions IA pendant les fenêtres ;
6. événements `day.advanced` / `week.advanced` ;
7. clôture de saison si nécessaire.

Un direct commencé par le joueur bloque l’avancement tant qu’il n’est pas terminé. Les transactions imbriquées de `Database` réutilisent la transaction du jour ; une exception annule donc l’ensemble de la journée.

`SeasonService` évalue les objectifs, applique promotions/relégations, expire les contrats, traite les retraites, génère deux jeunes par club et reconstruit budgets, standings, tactiques, plans et 480 fixtures de la nouvelle saison.

## Moteur de match

`TacticalMatchEngine` est un service pur sans PDO ni HTTP. `MatchService` possède la persistance :

- état JSON complet dans `fixtures.match_state` pour reprendre un direct ;
- verrouillage `SELECT ... FOR UPDATE` avant transition ;
- événements normalisés dans `match_events` ;
- statistiques et minute/score dénormalisés dans `fixtures` ;
- finalisation unique : classement, forme/moral/fatigue, blessures, messagerie et `match.finished`.

La tactique influence la force d’attaque, de milieu et de défense. Formation et poste déterminent l’adéquation ; mentalité, style, pressing, ligne, tempo, rôles et consignes modifient les coefficients. Le joueur prévu tire les penalties lorsqu’il est sur le terrain.

## Entraînement, mercato et finances

- `TrainingService` applique une seule fois chaque plan club/semaine, conserve les valeurs avant/après et marque le plan comme appliqué.
- `TransferService` centralise validation, négociation et finalisation. `ClubAIManager` évalue les offres et choisit les actions quotidiennes derrière `AIManagerInterface`.
- `FinanceService` est le seul point d’écriture du livre comptable. Les recettes/dépenses de match et de transfert proviennent d’événements pour éviter la duplication des règles dans les contrôleurs.
- `MessageService` et `MoraleService` sont partagés par la boucle et les autres services.

## Événements

Le dispatcher est synchrone et déterministe : priorité décroissante, puis ordre d’enregistrement. Les motifs avec `*` et `?` sont acceptés. Le noyau et les services émettent :

- `app.booted`, `request.handled` ;
- `match.finished`, `training.applied`, `transfer.completed` ;
- `day.advanced`, `week.advanced` ;
- `season.finished`, `season.started`.

Une exception de listener remonte à l’appelant et peut annuler la transaction courante. Les charges utiles exactes sont listées dans [EXTENDING.md](EXTENDING.md).

## Base et isolation des parties

`Database` crée PDO paresseusement avec les préparations natives (`ATTR_EMULATE_PREPARES=false`). Les valeurs sont liées selon leur type PHP. Toutes les lignes de simulation possèdent un `save_id` indexé directement ou dans un index composite.

Un identifiant de joueur, club ou fixture n’est jamais une preuve suffisante d’appartenance :

```sql
SELECT * FROM players WHERE id = :player_id AND save_id = :save_id;
```

`GameContext` vérifie aussi que la sauvegarde appartient à l’utilisateur de la session. Les suppressions cascadent vers les données de la partie ; certaines relations historiques utilisent `SET NULL`.

## Migrations

Les fichiers noyau sont triés par nom dans `database/migrations`, puis ceux des modules par nom de module et fichier. Leur identifiant est `<module>/<fichier>` pour ces derniers.

Le découpeur SQL accepte commentaires et points-virgules dans les littéraux. Les procédures utilisant `DELIMITER` ne sont pas supportées. Les dates variables de blessure sont calculées en PHP afin de ne pas dépendre du support des placeholders dans les intervalles MySQL/MariaDB.

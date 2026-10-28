# Architecture du socle

## Cycle d'une requête

1. `public/index.php` charge `bootstrap/app.php`.
2. L'autoloader PSR-4 enregistre les espaces `App\` et `Modules\`.
3. `Application::boot()` charge `.env`, la configuration et les services partagés.
4. Les modules sont découverts par priorité, leurs services sont enregistrés, puis leur méthode `boot()` est appelée.
5. La session démarre et les routes de tous les modules sont chargées.
6. Le middleware CSRF global précède les middlewares de route, puis le contrôleur.
7. L'événement `request.handled` est émis avant l'envoi de la `Response`.

Le contrôleur ne contient que l'orchestration HTTP. Les calculs métier iront dans `app/Services`, `app/Engine` ou les services d'un module.

## Modules

Un manifest `module.php` retourne au minimum :

- `name` : identifiant stable en minuscules, utilisé pour les vues et migrations ;
- `class` : classe implémentant `ModuleInterface` ;
- `priority` : ordre croissant de chargement, 100 par défaut ;
- `version` et `enabled` : métadonnées de cycle de vie.

La variable `MODULES_DISABLED` accepte une liste d'identifiants séparés par des virgules. Un module désactivé ne charge ni services, ni routes, ni migrations. Une migration de module ne doit donc pas être désactivée après son exploitation sans une décision de maintenance explicite.

Les vues sont automatiquement enregistrées sous `<module>::<vue>`. Par exemple, `system::status` correspond à `modules/System/views/status.php`.

## Événements

Le dispatcher est synchrone et déterministe : priorité décroissante, puis ordre d'enregistrement. Les motifs avec `*` et `?` sont acceptés. Un listener reçoit un objet `Event` et peut appeler `stopPropagation()`.

Le noyau émet actuellement :

- `app.booted` avec `application` ;
- `request.handled` avec `request` et `response`.

Les contrats `match.finished`, `week.advanced`, `transfer.completed` et `training.applied` sont réservés aux modules métier des étapes suivantes. Ils ne sont pas encore simulés par le socle.

## Base et isolation des parties

`Database` crée PDO paresseusement avec les préparations natives (`ATTR_EMULATE_PREPARES=false`). Les valeurs sont liées selon leur type PHP. La transaction de service évite d'ouvrir une transaction imbriquée si l'appelant en a déjà une.

Chaque ligne de simulation possède un `save_id` indexé directement ou dans un index composite. Les identifiants de joueur, club ou fixture ne sont jamais une preuve suffisante d'appartenance à une partie : les repositories devront toujours appliquer les deux prédicats.

```sql
SELECT * FROM players WHERE id = :player_id AND save_id = :save_id;
```

Les suppressions d'une sauvegarde cascadent vers ses données. Les relations historiques importantes utilisent selon les cas `SET NULL` ou `CASCADE`.

## Migrations

Les fichiers noyau sont triés par nom dans `database/migrations`. Les fichiers de module sont ensuite triés par nom de module puis par fichier. Leur identifiant enregistré est `<module>/<fichier>`, ce qui évite les collisions.

Le découpeur SQL accepte les commentaires et les points-virgules dans les littéraux. Les procédures utilisant `DELIMITER` ne sont pas supportées : elles devront avoir un exécuteur spécifique si un jour elles sont nécessaires.

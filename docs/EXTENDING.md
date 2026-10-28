# Étendre Tunisian Football Manager

L’application est organisée en modules découvrables, services autowirés et événements synchrones. Les algorithmes de match et d’IA sont remplaçables par interface. Ce guide décrit les API présentes dans le code, sans dépendance obligatoire.

## 1. Ajouter un module

Exemple d’un module de scouting :

```text
modules/Scouting/
  module.php
  ScoutingModule.php
  Controllers/ScoutingController.php
  Services/ScoutingService.php
  views/index.php
  migrations/001_create_scouting_tables.sql
  routes.php
```

### Manifest

```php
// modules/Scouting/module.php
use Modules\Scouting\ScoutingModule;

return [
    'name' => 'scouting',
    'version' => '1.0.0',
    'class' => ScoutingModule::class,
    'priority' => 120,
];
```

Le nom utilise les minuscules et devient le namespace des vues (`scouting::index`). Les modules sont triés par priorité croissante. `MODULES_DISABLED=scouting,finances` désactive les modules listés ; ne pas désactiver un module dont les données sont encore exploitées sans plan de maintenance.

### Services et listener

```php
namespace Modules\Scouting;

use App\Contracts\ModuleInterface;
use App\Core\Application;
use App\Events\Event;
use Modules\Scouting\Services\ScoutingService;

final class ScoutingModule implements ModuleInterface
{
    public function register(Application $app): void
    {
        $app->container()->singleton(ScoutingService::class);
    }

    public function boot(Application $app): void
    {
        $app->events()->listen('match.finished', function (Event $event) use ($app): void {
            $service = $app->make(ScoutingService::class);
            $fixture = $event->get('fixture');
            // Enregistrer une observation sans modifier le moteur de match.
        }, 20);
    }
}
```

Les classes concrètes sont autowirées par le conteneur. Les paramètres scalaires de constructeur nécessitent un binding explicite. Les implémentations d’un algorithme remplaçable doivent être enregistrées **par interface**.

### Routes protégées

```php
// modules/Scouting/routes.php
use App\Core\Application;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\SaveMiddleware;
use Modules\Scouting\Controllers\ScoutingController;

return static function (Router $router, Application $app): void {
    $router->get('/saves/{save}/scouting', [ScoutingController::class, 'index'])
        ->where('save', '[0-9]+')
        ->middleware(AuthMiddleware::class, SaveMiddleware::class);
};
```

`AuthMiddleware` exige un compte connecté. `SaveMiddleware` charge `GameContext` après avoir vérifié que la sauvegarde appartient à cet utilisateur. Une action reçoit `Request`, puis les paramètres de route dans leur ordre de déclaration et retourne une `Response`.

Les contrôleurs délèguent les calculs aux services. Les sorties dynamiques des vues utilisent `e($value)`. Un formulaire de modification contient `_token` avec `csrf_token()` ; un appel `fetch` peut envoyer `X-CSRF-Token`.

## 2. Événements disponibles

Le dispatcher est synchrone : priorité décroissante, puis ordre d’enregistrement. Les motifs `match.*` sont acceptés. Un listener reçoit `App\Events\Event` et peut appeler `stopPropagation()`.

| Événement | Charge utile principale |
|---|---|
| `app.booted` | `application` |
| `request.handled` | `request`, `response` |
| `match.finished` | `save_id`, `fixture_id`, `fixture`, `state` |
| `training.applied` | `save_id`, `club_id`, `training_plan_id`, `training_report_id` |
| `transfer.completed` | IDs des clubs/joueur/transfert, `fee`, `signing_bonus`, `season_id`, `date` |
| `day.advanced` | `save_id`, `date` |
| `week.advanced` | `save_id`, `club_id`, `date` |
| `season.finished` | `save_id`, `season_id` |
| `season.started` | `save_id`, `season_id` |

Exemple :

```php
$app->events()->listen('training.applied', function (Event $event): void {
    $reportId = (int)$event->get('training_report_id');
}, 10);
```

Règles :

- nommer un événement au passé pour un fait accompli ;
- transporter les identifiants et les données nécessaires, sans requête HTTP ni affichage ;
- conserver `save_id` dans toute lecture/écriture de simulation ;
- utiliser les priorités seulement lorsque l’ordre est requis ;
- ne pas supposer qu’un listener est asynchrone : une exception remonte dans la transaction appelante.

`FinancesModule` fournit un exemple de listeners pour `match.finished` et `transfer.completed`.

## 3. Remplacer le moteur de match

`MatchService` dépend de `App\Contracts\MatchEngineInterface`. Le module Match enregistre actuellement :

```php
$app->container()->singleton(TacticalMatchEngine::class);
$app->container()->singleton(MatchEngineInterface::class, TacticalMatchEngine::class);
```

Une implémentation alternative doit fournir :

```php
initialize(array $homePlayers, array $awayPlayers, array $homeTactic, array $awayTactic, array $options = []): array;
advance(array $state, bool $ignoreHalftime = false): array;
substitute(array $state, string $side, int $outPlayerId, int $inPlayerId): array;
changeTactic(array $state, string $side, array $tactic): array;
```

L’état contient notamment la minute, le statut, le score, les deux équipes, les statistiques et `new_events`. Chaque événement suit la structure du moteur actuel : `minute`, `type`, `side`, `description`, `player_id`, `related_player_id`, `data`.

Contraintes importantes :

- le moteur ne doit dépendre ni de PDO ni de HTTP ;
- préserver la reprise d’un état JSON déjà persisté ;
- produire exactement 11 titulaires distincts et respecter le nombre maximal de remplacements ;
- conserver la cohérence entre score, statistiques et événements ;
- ajouter des tests analogues à `tests/engine.php` avant de changer le binding.

Les tactiques interprétent les formations, rôles (`support`, `attack`, `defend`, `playmaker`, `target`) et consignes (`normal`, `attack`, `stay_back`). Une nouvelle formation exige actuellement de mettre à jour `TacticService::FORMATIONS` et les coordonnées de `public/assets/js/tactics.js`.

## 4. Remplacer l’IA des clubs

`TransferService` dépend de `App\Contracts\AIManagerInterface`, liée à `ClubAIManager` dans `TransfersModule`.

L’interface couvre :

- `evaluateTransferOffer(array $offer, array $player, string $perspective): array` avec une décision `accept`, `reject` ou `counter` ;
- `chooseDailyActions(int $saveId, string $date): array` pour les opérations IA et offres adressées au joueur.

Une IA avancée peut exploiter les besoins par poste, les finances ou le scouting sans modifier le contrôleur. Elle doit respecter les fenêtres et l’isolation des sauvegardes ; la finalisation et l’historique restent dans `TransferService`.

## 5. Ajouter une migration

Créer `modules/Scouting/migrations/001_create_scouting_tables.sql`, puis lancer :

```bash
php bin/migrate
```

L’identifiant enregistré sera `scouting/001_create_scouting_tables.sql`. Une table de simulation doit contenir :

```sql
`save_id` BIGINT UNSIGNED NOT NULL,
KEY `idx_scouting_save` (`save_id`),
CONSTRAINT `fk_scouting_save` FOREIGN KEY (`save_id`)
    REFERENCES `saves` (`id`) ON DELETE CASCADE
```

Utiliser des noms de contraintes uniques dans toute la base. Une migration déjà livrée n’est jamais modifiée : créer une migration incrémentale. Les procédures avec `DELIMITER` ne sont pas supportées par le découpeur actuel.

## 6. Modifier l’équilibrage

Les coefficients se trouvent dans `config/game.php` : difficultés, fenêtres de mercato, matchs, entraînement et finances. Ajouter une clé dans ce fichier et la lire avec `Config::get('game.section.key', $default)` au lieu de disperser des constantes dans les contrôleurs.

Une nouvelle valeur stockée dans une colonne SQL `ENUM` exige aussi une migration. Par exemple, un nouveau `focus` d’entraînement nécessite :

1. la clé `training.focus_attributes` ;
2. la migration de `training_plans.focus` ;
3. le libellé et l’icône de la vue ;
4. les tests de validation et d’application.

## 7. Traductions et interface

Ajouter la même clé dans `lang/fr.php`, `lang/en.php` et `lang/ar.php` :

```php
echo e(__('scouting.report_title'));
echo e(__('scouting.player_count', ['count' => 12]));
```

Le français est la locale de repli. Les vues métier actuelles contiennent principalement des libellés français ; une internationalisation complète consiste à remplacer progressivement ces libellés par des clés. Le front n’a pas d’étape de compilation : conserver le JavaScript ES6+ et les variables CSS du design system.

## 8. Checklist d’une extension

- autowiring et bindings vérifiés avec `php tests/smoke.php` ;
- logique hors contrôleur, aucune donnée croisée entre sauvegardes ;
- entrées validées, requêtes préparées, sorties échappées et CSRF conservé ;
- migration incrémentale et indexes sur `save_id` ;
- tests unitaires du nouvel algorithme sans HTTP/PDO lorsque possible ;
- tests réels MySQL/MariaDB et navigateur pour le parcours complet ajouté.

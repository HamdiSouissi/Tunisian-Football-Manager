# Étendre Tunisian Football Manager

Ce guide couvre les points d'extension disponibles à l'étape 1 : modules, services, routes, vues, hooks et migrations. Les catalogues de tactiques et de types d'entraînement seront documentés avec leurs moteurs respectifs aux étapes 4 et 5.

## 1. Ajouter un module

Exemple d'un module de scouting, sans modification du noyau :

```text
modules/Scouting/
  module.php
  ScoutingModule.php
  Controllers/ScoutingController.php
  Models/
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

### Classe du module et services

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
        $app->events()->listen('match.finished', function (Event $event): void {
            $service = $app->make(ScoutingService::class);
            // Exploiter $event->get('fixture_id') sans modifier le moteur de match.
        }, priority: 20);
    }
}
```

Un composant dont l'algorithme doit être remplaçable doit être enregistré **par son interface** (`MatchEngineInterface`, `AIManagerInterface`, etc. lorsqu'ils seront introduits), pas seulement par son nom de classe.

### Routes et contrôleurs

```php
// modules/Scouting/routes.php
use App\Core\Application;
use App\Core\Router;
use Modules\Scouting\Controllers\ScoutingController;

return static function (Router $router, Application $app): void {
    $router->get('/saves/{save}/scouting', [ScoutingController::class, 'index'])
        ->where('save', '[0-9]+');
};
```

Une action de contrôleur reçoit `Request`, puis les paramètres de route dans leur ordre de déclaration :

```php
public function index(Request $request, string $save): Response
{
    // Le contrôleur valide l'accès puis délègue au service.
}
```

Les actions doivent retourner `Response`. Les sorties dynamiques des vues utilisent `e($value)`. Le formulaire d'une action de modification envoie `_token` avec `csrf_token()` ; un appel `fetch` peut utiliser l'en-tête `X-CSRF-Token`.

## 2. Ajouter ou écouter un hook

```php
// Producteur, dans un service métier
$app->events()->dispatch('training.applied', [
    'save_id' => $saveId,
    'club_id' => $clubId,
    'training_plan_id' => $planId,
]);

// Consommateur, dans la méthode boot() d'un autre module
$app->events()->listen('training.applied', function (Event $event): void {
    $saveId = $event->get('save_id');
}, priority: 10);
```

Règles :

- nommer les événements au passé pour un fait accompli (`transfer.completed`) ;
- transporter les identifiants plutôt que de grands objets mutables ;
- ne pas faire de requête HTTP ou d'affichage dans un listener ;
- utiliser les priorités uniquement lorsqu'un ordre est réellement requis ;
- pour stopper les listeners suivants, appeler `$event->stopPropagation()`.

Les motifs comme `match.*` permettent d'écouter une famille d'événements.

## 3. Ajouter une migration de module

Créer `modules/Scouting/migrations/001_create_scouting_tables.sql`, puis lancer :

```bash
php bin/migrate
```

L'identifiant enregistré sera `scouting/001_create_scouting_tables.sql`. Une table appartenant à une partie doit contenir :

```sql
`save_id` BIGINT UNSIGNED NOT NULL,
KEY `idx_scouting_save` (`save_id`),
CONSTRAINT `fk_scouting_save` FOREIGN KEY (`save_id`)
    REFERENCES `saves` (`id`) ON DELETE CASCADE
```

Utiliser des noms de contraintes uniques dans toute la base. Une migration déjà livrée n'est jamais modifiée : créer une migration incrémentale.

## 4. Ajouter une traduction

Ajouter la même clé dans `lang/fr.php`, `lang/en.php` et `lang/ar.php`, puis l'utiliser avec :

```php
echo e(__('scouting.report_title'));
echo e(__('scouting.player_count', ['count' => 12]));
```

Le français est la locale de repli. L'arabe utilise automatiquement la direction RTL dans le layout lorsque la locale active est `ar`.

## 5. Conventions pour les futurs catalogues tactiques et d'entraînement

Les formations et focus disposent déjà de colonnes de stockage dans `tactics` et `training_plans`. Leur comportement ne doit pas être ajouté par une condition dans un contrôleur :

- une **tactique** sera décrite par une configuration et interprétée par le service/moteur derrière son interface ;
- un **type d'entraînement** sera décrit par une configuration et appliqué par `TrainingService` ;
- les effets sur les joueurs déclencheront les hooks appropriés ;
- les paramètres d'équilibrage résideront dans `config/`, jamais en constantes dispersées.

Ces contrats seront figés et illustrés avec l'implémentation des étapes 4 et 5 afin que la documentation ne décrive pas une API inexistante.

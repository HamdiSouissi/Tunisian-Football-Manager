# Tunisian Football Manager

Application web de simulation de management de football tunisien, développée en **PHP 8.1+ natif**, sans framework ni dépendance obligatoire.

> **Livraison actuelle : étape 1/8 — architecture, noyau et schéma SQL.** Les données de clubs, l'authentification et les mécaniques de jeu sont volontairement planifiés dans les étapes suivantes.

## Socle disponible

- Front controller `public/index.php`, URLs propres Apache et routeur du serveur PHP intégré.
- Autoload PSR-4 maison : `App\` → `app/`, `Modules\` → `modules/`.
- Routeur GET/POST/PUT/PATCH/DELETE, paramètres typés par expressions régulières et middlewares.
- Conteneur de services avec autowiring, contrôleurs de base et vues PHP modulaires.
- PDO MySQL avec requêtes préparées et transactions.
- Migrations SQL versionnées, journalisées par lot et extensibles par les modules.
- Découverte automatique des modules via `modules/<Nom>/module.php`.
- `EventDispatcher` synchrone avec priorités et motifs (`match.*`).
- Sessions sécurisées, protection CSRF globale et échappement HTML via `e()`.
- Traductions initiales français, arabe et anglais.
- Schéma complet pour les utilisateurs, parties, clubs, joueurs, matchs, tactiques, entraînements, transferts, finances et messages.

## Prérequis

- PHP **8.1 ou supérieur** avec `pdo_mysql`, `session` et `json` ;
- MySQL 8+ ou MariaDB 10.4+ ;
- Apache avec `mod_rewrite` pour la production, ou le serveur PHP intégré en développement ;
- aucun Composer ni build JavaScript n'est nécessaire.

## Installation

### 1. Configurer l'environnement

```bash
cp .env.example .env
```

Renseigner les accès `DB_*`. Créer préalablement la base et un utilisateur dédié, par exemple :

```sql
CREATE DATABASE tunisian_football_manager
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tfm'@'localhost' IDENTIFIED BY 'un-mot-de-passe-fort';
GRANT ALL ON tunisian_football_manager.* TO 'tfm'@'localhost';
```

Ne jamais committer `.env`. En production : `APP_DEBUG=false` et, sous HTTPS, `SESSION_SECURE_COOKIE=true`. Les répertoires `storage/logs` et `storage/sessions` doivent être accessibles en écriture par PHP ; `SESSION_SAVE_PATH` permet de choisir un autre chemin absolu pour les sessions.

### 2. Exécuter les migrations

```bash
php bin/migrate
php bin/migrate status
```

La table `schema_migrations` empêche toute réexécution. Les migrations du noyau sont chargées avant celles des modules. Les DDL MySQL/MariaDB validant implicitement les transactions, une migration interrompue doit être inspectée avant toute nouvelle tentative.

### 3. Démarrer l'application

Avec le serveur intégré :

```bash
php -S 0.0.0.0:8000 -t public public/router.php
```

Puis ouvrir `http://localhost:8000`. La page d'accueil de cette étape ne nécessite pas de connexion à la base. `GET /health` fournit une réponse JSON de diagnostic.

Avec Apache, le **DocumentRoot doit pointer vers `public/`** ; ne jamais exposer la racine du projet.

## Vérifications

```bash
# Syntaxe de tous les fichiers PHP
find app bootstrap config lang modules public tests -name '*.php' -print0 | xargs -0 -n1 php -l
php -l bin/migrate

# Smoke tests du conteneur, des hooks, du CSRF, des migrations et du routeur (sans base)
php tests/smoke.php
```

## Organisation

```text
app/
  Controllers/       Contrôleur abstrait, puis contrôleurs transverses
  Contracts/         Interfaces du noyau et futurs contrats remplaçables
  Core/              Application, routeur, HTTP, vues, modules, configuration
  Database/          PDO et exécuteur de migrations
  Events/            Événements et dispatcher
  Middleware/        Middlewares HTTP
  Models/            Modèles des prochaines étapes
  Security/          CSRF et futurs composants de sécurité
  Services/          Services métier à venir
  Session/           Gestion sécurisée des sessions
  Support/           Fonctions d'aide globales
  Translation/       Chargeur de traductions
bootstrap/           Amorçage et autoload
bin/migrate          Commande CLI des migrations
config/              Configuration centralisée
database/
  migrations/        Schéma SQL versionné
  seeders/           Seeders de l'étape 2
  data/              Futures données JSON/CSV tunisiennes
docs/                Architecture et guide d'extension
lang/                fr.php, ar.php, en.php
modules/
  System/            Exemple minimal auto-enregistré et diagnostic HTTP
public/              Seul répertoire exposé au web
storage/             Journaux, cache et sessions locaux
```

## Schéma de données

| Migration | Tables |
|---|---|
| `001_create_identity_and_world_tables` | `users`, `saves`, `leagues`, `clubs`, `seasons`, `players`, `player_attributes`, `contracts`, `staff` |
| `002_create_match_tables` | `fixtures`, `match_events`, `standings`, `tactics` |
| `003_create_training_tables` | `training_plans`, `training_plan_players`, `training_reports`, `training_report_entries` |
| `004_create_transfer_finance_and_message_tables` | `transfers`, `transfer_offers`, `loans`, `shortlisted_players`, `finances`, `board_objectives`, `messages`, `settings` |

Toutes les tables de simulation contiennent `save_id`. Toute requête métier devra filtrer explicitement sur cette clé en plus de l'identifiant technique. Les attributs sont conçus sur une échelle de 1 à 100 et les montants utilisent `DECIMAL`.

## Conventions et sécurité

- code et noms de classes en anglais, commentaires en français, `strict_types=1` ;
- requêtes préparées pour toute valeur dynamique ;
- échappement de toute sortie dynamique avec `e()` ;
- CSRF obligatoire sur les méthodes de modification, par champ `_token` ou en-tête `X-CSRF-Token` ;
- régénération de l'identifiant de session prévue lors de l'authentification ;
- secrets uniquement dans l'environnement, erreurs détaillées uniquement en mode debug.

Voir [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) et [docs/EXTENDING.md](docs/EXTENDING.md).

## Feuille de route

1. **Architecture, noyau, schéma et migrations — cette livraison.**
2. Authentification, création de parties et seeders tunisiens.
3. Design system, tableau de bord, effectif et fiche joueur.
4. Tactique, moteur de match, calendrier et classement.
5. Entraînement et évolution.
6. Mercato et IA des clubs.
7. Finances, messagerie, objectifs et fin de saison.
8. Équilibrage, tests approfondis et documentation finale.

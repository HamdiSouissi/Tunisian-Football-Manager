# Tunisian Football Manager

Jeu web de gestion d’un club de football tunisien, écrit en **PHP 8.1+ natif**, sans framework ni dépendance obligatoire. L’interface utilise HTML5, CSS3 et JavaScript ES6+ ; aucun build front n’est nécessaire.

> **État de validation :** les tests sans base (noyau, autowiring, routes invitées, CSRF, datasets, moteur de match et calendrier) sont exécutés et passent. L’environnement de développement de cette livraison ne disposait pas de serveur MySQL/MariaDB ni de navigateur : les parcours persistants complets et l’affichage navigateur doivent être vérifiés sur votre installation avant de considérer ces parcours comme validés. Voir [docs/TESTING.md](docs/TESTING.md).

## Fonctionnalités implémentées

- **Carrières** : inscription, connexion, déconnexion, plusieurs sauvegardes, archivage, quatre difficultés et isolation stricte par `save_id`.
- **Monde tunisien** : deux divisions de 16 clubs, 22 joueurs par club, 12 joueurs libres, contrats, staff, tactiques, objectifs et 480 rencontres aller-retour.
- **Identité visuelle locale** : 32 blasons PNG en 256 × 256, dont 31 importés depuis leurs sources Internet et un monogramme de repli documenté ; aucun hotlink pendant le jeu. Voir [les sources des assets](public/assets/img/clubs/SOURCES.md).
- **Interface plein écran** : coquille verrouillée à `100vw` / `100dvh`, sans défilement du document ; le contenu principal, la navigation et les listes denses possèdent leurs propres zones de défilement.
- **Centre de commandement** : prochain match, classement, objectif et confiance de la direction, finances, joueurs en vue, résultats et messagerie.
- **Effectif** : tableau dense triable/filtrable, forme, moral, fatigue, blessures, valeur, fiche détaillée et radar d’attributs sans bibliothèque externe.
- **Tactique** : six formations, onze titulaires, sept remplaçants, glisser-déposer et sélection au clic, mentalité, style, pressing, ligne, tempo, largeur, rôles et consignes individuelles interprétés par le moteur.
- **Matchs** : moteur minute par minute, occasions, buts, passes décisives, cartons, blessures, possession, fatigue, avantage domicile, direct interactif, lecture automatique, mi-temps, cinq remplacements et ajustements tactiques.
- **Championnat** : calendrier, résultats, statistiques, classement recalculé, forme et boucle d’avancement d’un à sept jours.
- **Entraînement** : programme collectif hebdomadaire, suivi individuel, intensité, qualité du staff, progression selon âge/potentiel, fatigue, blessures et rapports persistants.
- **Mercato** : recherche multicritère, achats, ventes, joueurs libres, négociations salariales, offres, contre-offres, prêts et retours, fenêtres de transferts, shortlist, historique et actions des clubs IA.
- **Finances et direction** : billetterie, salaires joueurs/staff, sponsors, droits TV, opérations de transfert, primes à la signature, budget, livre comptable, objectifs et licenciement lorsque la confiance tombe à zéro.
- **Fin de saison** : bilan des objectifs, promotions/relégations, expiration des contrats, retraites, jeunes du centre de formation, nouveaux budgets, tactiques et calendrier de la saison suivante.

## Prérequis

- PHP **8.1 ou supérieur** avec `pdo_mysql`, `session`, `json` et `openssl` ;
- MySQL 8+ ou MariaDB 10.4+ ;
- Apache avec `mod_rewrite`, ou le serveur PHP intégré en développement ;
- aucun Composer, npm ou build JavaScript n’est requis pour exploiter l’application.

## Installation

### 1. Créer la base et configurer l’environnement

```bash
cp .env.example .env
```

Exemple de base et d’utilisateur dédié :

```sql
CREATE DATABASE tunisian_football_manager
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tfm'@'localhost' IDENTIFIED BY 'un-mot-de-passe-fort';
GRANT ALL ON tunisian_football_manager.* TO 'tfm'@'localhost';
```

Renseigner ensuite `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` et `DB_PASSWORD`. `DB_SOCKET` peut remplacer la connexion TCP sur certains hébergements.

Ne jamais committer `.env`. En production :

```dotenv
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
```

`SESSION_SECURE_COOKIE=true` suppose HTTPS. Les répertoires `storage/logs` et `storage/sessions` doivent être accessibles en écriture par PHP. `SESSION_SAVE_PATH` permet de définir un autre chemin absolu.

### 2. Exécuter les migrations

```bash
php bin/migrate
php bin/migrate status
```

Les migrations créent le schéma, les clés étrangères, les index et l’état persistant des matchs. La table `schema_migrations` empêche leur réexécution. Les DDL MySQL/MariaDB validant implicitement les transactions, une migration interrompue doit être inspectée avant une nouvelle tentative.

### 3. Démarrer l’application

```bash
php -S 0.0.0.0:8000 -t public public/router.php
```

Ouvrir `http://localhost:8000`, créer un compte puis une carrière. La génération du monde est transactionnelle et peut prendre quelques secondes.

Avec Apache, le **DocumentRoot doit pointer vers `public/`**. Ne jamais exposer la racine du projet, `.env`, `storage/` ou `database/`.

### 4. Vérifier le premier seed

Dans la base nouvellement générée, pour une première sauvegarde sans autre partie dans le même monde :

```sql
SELECT COUNT(*) FROM clubs;          -- 32
SELECT COUNT(*) FROM players;        -- 716 au seed initial
SELECT COUNT(*) FROM fixtures;       -- 480
SELECT COUNT(*) FROM leagues;        -- 2
```

Ces compteurs sont globaux à la base : avec plusieurs sauvegardes, filtrer avec `WHERE save_id = ...` et multiplier les valeurs initiales par le nombre de mondes créés.

## Jouer

1. **Effectif / Tactique** : vérifier le onze, les remplaçants et les consignes, puis enregistrer.
2. **Continuer** : avancer d’un jour ou de sept jours. Les matchs du jour, l’entraînement du lundi, la récupération, les finances et les actions IA sont traités dans la boucle.
3. **Match interactif** : depuis le dashboard ou le calendrier, ouvrir le match de votre club à sa date. Il peut être joué minute par minute au lieu d’être simulé automatiquement.
4. **Entraînement** : modifier le programme avant lundi et consulter le rapport après application.
5. **Mercato** : recruter pendant les fenêtres du 15 juin au 31 août et du 1er au 31 janvier.
6. **Fin de saison** : après le 31 mai, continuer pour clôturer la saison et générer la suivante.

Un match commencé en direct doit être terminé avant de poursuivre l’avancement du temps. Une carrière archivée ou terminée reste consultable mais ne peut plus avancer.

## Tests et vérifications

```bash
# Syntaxe PHP
find app bootstrap config lang modules public tests -name '*.php' -print0 | xargs -0 -n1 php -l
php -l bin/migrate

# Noyau, autowiring, modules, CSRF, routes invitées, migrations et datasets
php tests/smoke.php

# Moteur minute par minute, rôles, remplacements, calendrier, IA et fenêtres
php tests/engine.php

# Syntaxe JavaScript si Node.js est disponible (non requis par l’application)
for file in public/assets/js/*.js; do node --check "$file" || exit 1; done
```

Le détail des vérifications réellement exécutées et des tests restant à faire sur MySQL/navigateur figure dans [docs/TESTING.md](docs/TESTING.md).

## Organisation

```text
app/
  Contracts/         Interfaces remplaçables (moteur, IA, événements, modules)
  Controllers/       Contrôleurs transverses et aides HTTP
  Core/              Application, conteneur, routeur, vues, configuration
  Database/          PDO et migrations
  Engine/            Moteur de match sans dépendance HTTP/PDO
  Events/            Dispatcher synchrone
  Middleware/        Authentification, sauvegarde et CSRF
  Models/            Repositories et requêtes de lecture
  Security/          CSRF et validation
  Services/          Seeding, boucle, matchs, entraînement, mercato, finances…
  Session/           Sessions sécurisées
  Support/           Helpers globaux
bootstrap/           Amorçage et PSR-4 maison
config/              Configuration applicative et équilibrage central
bin/migrate          Commande CLI des migrations
database/
  data/              Clubs, noms et joueurs libres
  migrations/        Schéma SQL versionné
modules/
  Auth/              Comptes et carrières
  Game/              Dashboard, messagerie, club et staff
  Squad/             Effectif et fiches joueurs
  Tactics/           Éditeur tactique
  Match/             Direct interactif
  League/            Calendrier et classements
  Training/          Programmes et rapports
  Transfers/         Mercato et négociations
  Finances/          Budgets et listeners financiers
  System/            Diagnostic du noyau
public/              Seul répertoire exposé au web
storage/             Journaux, cache et sessions
tests/                Smoke tests et tests du moteur
```

## Schéma et isolation

| Migration | Contenu principal |
|---|---|
| `001_create_identity_and_world_tables` | utilisateurs, saves, ligues, clubs, saisons, joueurs, attributs, contrats, staff |
| `002_create_match_tables` | fixtures, événements, standings, tactiques |
| `003_create_training_tables` | plans, suivis individuels et rapports |
| `004_create_transfer_finance_and_message_tables` | transferts, offres, prêts, shortlist, finances, objectifs, messages, paramètres |
| `005_add_live_match_state` | état JSON reprenable du direct |

Toutes les données de simulation portent `save_id`. Les repositories et services doivent toujours filtrer sur cette clé en plus de l’identifiant technique.

## Conventions, sécurité et extension

- classes et variables en anglais, commentaires en français, `strict_types=1` ;
- PDO avec requêtes préparées natives et transactions ;
- `password_hash` / `password_verify`, régénération de session à l’authentification ;
- CSRF global sur toute méthode de modification, champ `_token` ou en-tête `X-CSRF-Token` ;
- validation des entrées et échappement des sorties avec `e()` ;
- paramètres d’équilibrage centralisés dans `config/game.php` ;
- algorithmes remplaçables derrière `MatchEngineInterface` et `AIManagerInterface` ;
- aucune logique de simulation dans les contrôleurs ou les vues.

Guides : [architecture](docs/ARCHITECTURE.md), [extension des modules et moteurs](docs/EXTENDING.md), [validation](docs/TESTING.md).

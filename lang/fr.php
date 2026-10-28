<?php

declare(strict_types=1);

return [
    'app' => ['name' => 'Tunisian Football Manager'],
    'status' => [
        'eyebrow' => 'Étape 1 · Architecture et noyau',
        'kernel_ready' => 'Noyau opérationnel',
        'title' => 'Le socle technique est prêt à faire grandir le jeu.',
        'description' => 'Un point d’entrée unique, des modules autonomes et une base de données versionnée pour bâtir la simulation tunisienne sur des fondations solides.',
        'foundation' => 'Fondations',
        'feature_mvc' => 'MVC, routeur et autoload PSR-4 natifs',
        'feature_pdo' => 'PDO sécurisé et migrations multi-modules',
        'feature_security' => 'Sessions durcies et protection CSRF globale',
        'feature_hooks' => 'Hooks synchrones et traductions fr / ar / en',
        'environment' => 'Environnement',
        'environment_name' => 'Contexte',
        'modules' => 'Modules actifs',
        'database' => 'Initialiser le schéma MySQL / MariaDB',
        'database_help' => 'Après avoir renseigné le fichier .env et créé la base, lancez les migrations versionnées :',
        'health_link' => 'Vérifier la réponse JSON du noyau',
        'footer' => 'Étape 1 sur 8 · Les données et fonctionnalités métier seront livrées progressivement.',
    ],
    'errors' => [
        'not_found' => 'La page demandée est introuvable.',
        'server' => 'Une erreur interne est survenue.',
        'csrf' => 'La session a expiré. Rechargez la page et réessayez.',
    ],
];

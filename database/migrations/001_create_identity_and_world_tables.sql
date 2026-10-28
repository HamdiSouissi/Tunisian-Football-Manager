-- Étape 1 : utilisateurs, parties et monde footballistique.
-- Toutes les données évolutives d'une partie portent un save_id.

CREATE TABLE `users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
    `remember_token` VARCHAR(100) NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `saves` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `manager_name` VARCHAR(120) NOT NULL,
    `difficulty` ENUM('easy', 'normal', 'hard', 'legend') NOT NULL DEFAULT 'normal',
    `club_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `current_season_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `current_date` DATE NOT NULL,
    `status` ENUM('active', 'archived', 'completed') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_saves_user_status` (`user_id`, `status`),
    KEY `idx_saves_club` (`club_id`),
    KEY `idx_saves_current_season` (`current_season_id`),
    CONSTRAINT `fk_saves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leagues` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `save_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `short_name` VARCHAR(30) NOT NULL,
    `slug` VARCHAR(120) NOT NULL,
    `level` TINYINT UNSIGNED NOT NULL,
    `country` VARCHAR(80) NOT NULL DEFAULT 'Tunisia',
    `team_count` TINYINT UNSIGNED NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_leagues_save_slug` (`save_id`, `slug`),
    UNIQUE KEY `uq_leagues_save_level` (`save_id`, `level`),
    CONSTRAINT `fk_leagues_save` FOREIGN KEY (`save_id`) REFERENCES `saves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `clubs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `save_id` BIGINT UNSIGNED NOT NULL,
    `league_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `name` VARCHAR(140) NOT NULL,
    `short_name` VARCHAR(40) NOT NULL,
    `slug` VARCHAR(140) NOT NULL,
    `city` VARCHAR(100) NOT NULL,
    `stadium_name` VARCHAR(140) NOT NULL,
    `stadium_capacity` INT UNSIGNED NOT NULL DEFAULT 0,
    `primary_color` CHAR(7) NOT NULL DEFAULT '#164e63',
    `secondary_color` CHAR(7) NOT NULL DEFAULT '#f8fafc',
    `reputation` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `transfer_budget` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `wage_budget` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `board_confidence` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `is_playable` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_clubs_save_slug` (`save_id`, `slug`),
    UNIQUE KEY `uq_clubs_save_name` (`save_id`, `name`),
    KEY `idx_clubs_league` (`league_id`),
    KEY `idx_clubs_save_reputation` (`save_id`, `reputation`),
    CONSTRAINT `fk_clubs_save` FOREIGN KEY (`save_id`) REFERENCES `saves` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_clubs_league` FOREIGN KEY (`league_id`) REFERENCES `leagues` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `seasons` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `save_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(40) NOT NULL,
    `starts_on` DATE NOT NULL,
    `ends_on` DATE NOT NULL,
    `current_matchday` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `status` ENUM('upcoming', 'active', 'finished') NOT NULL DEFAULT 'upcoming',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_seasons_save_name` (`save_id`, `name`),
    KEY `idx_seasons_save_status` (`save_id`, `status`),
    CONSTRAINT `fk_seasons_save` FOREIGN KEY (`save_id`) REFERENCES `saves` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les deux contraintes sont ajoutées après la création pour rompre le cycle de création.
ALTER TABLE `saves`
    ADD CONSTRAINT `fk_saves_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_saves_current_season` FOREIGN KEY (`current_season_id`) REFERENCES `seasons` (`id`) ON DELETE SET NULL;

CREATE TABLE `players` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `save_id` BIGINT UNSIGNED NOT NULL,
    `club_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `first_name` VARCHAR(80) NOT NULL,
    `last_name` VARCHAR(80) NOT NULL,
    `birth_date` DATE NOT NULL,
    `nationality` VARCHAR(80) NOT NULL DEFAULT 'Tunisia',
    `primary_position` VARCHAR(10) NOT NULL,
    `secondary_positions` JSON NULL,
    `preferred_foot` ENUM('left', 'right', 'both') NOT NULL DEFAULT 'right',
    `height_cm` SMALLINT UNSIGNED NULL DEFAULT NULL,
    `weight_kg` SMALLINT UNSIGNED NULL DEFAULT NULL,
    `market_value` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `overall_rating` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `potential` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `form` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `morale` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `fatigue` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `injury_type` VARCHAR(100) NULL DEFAULT NULL,
    `injury_until` DATE NULL DEFAULT NULL,
    `is_retired` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_players_save_club` (`save_id`, `club_id`),
    KEY `idx_players_club` (`club_id`),
    KEY `idx_players_save_position` (`save_id`, `primary_position`),
    KEY `idx_players_save_value` (`save_id`, `market_value`),
    KEY `idx_players_name` (`last_name`, `first_name`),
    KEY `idx_players_injury_until` (`injury_until`),
    CONSTRAINT `fk_players_save` FOREIGN KEY (`save_id`) REFERENCES `saves` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_players_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `player_attributes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `save_id` BIGINT UNSIGNED NOT NULL,
    `player_id` BIGINT UNSIGNED NOT NULL,
    `passing` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `dribbling` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `shooting` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `first_touch` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `tackling` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `vision` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `composure` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `work_rate` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `positioning` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `pace` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `stamina` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `strength` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `jumping` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `reflexes` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `handling` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `aerial_reach` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `command_of_area` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `kicking` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_player_attributes_player` (`player_id`),
    KEY `idx_player_attributes_save` (`save_id`),
    CONSTRAINT `fk_player_attributes_save` FOREIGN KEY (`save_id`) REFERENCES `saves` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_player_attributes_player` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `contracts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `save_id` BIGINT UNSIGNED NOT NULL,
    `player_id` BIGINT UNSIGNED NOT NULL,
    `club_id` BIGINT UNSIGNED NOT NULL,
    `starts_on` DATE NOT NULL,
    `ends_on` DATE NOT NULL,
    `salary` DECIMAL(13,2) NOT NULL,
    `signing_bonus` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `release_clause` DECIMAL(15,2) NULL DEFAULT NULL,
    `squad_role` ENUM('key_player', 'regular', 'rotation', 'backup', 'prospect') NOT NULL DEFAULT 'regular',
    `status` ENUM('active', 'expired', 'terminated') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_contracts_save_player_status` (`save_id`, `player_id`, `status`),
    KEY `idx_contracts_player` (`player_id`),
    KEY `idx_contracts_club` (`club_id`),
    KEY `idx_contracts_save_club_end` (`save_id`, `club_id`, `ends_on`),
    CONSTRAINT `fk_contracts_save` FOREIGN KEY (`save_id`) REFERENCES `saves` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_contracts_player` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_contracts_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `staff` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `save_id` BIGINT UNSIGNED NOT NULL,
    `club_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `first_name` VARCHAR(80) NOT NULL,
    `last_name` VARCHAR(80) NOT NULL,
    `role` ENUM('assistant', 'fitness_coach', 'doctor', 'goalkeeping_coach', 'youth_coach', 'scout') NOT NULL,
    `quality` TINYINT UNSIGNED NOT NULL DEFAULT 50,
    `wage` DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    `contract_ends_on` DATE NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_staff_save_club_role` (`save_id`, `club_id`, `role`),
    KEY `idx_staff_club` (`club_id`),
    CONSTRAINT `fk_staff_save` FOREIGN KEY (`save_id`) REFERENCES `saves` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_staff_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

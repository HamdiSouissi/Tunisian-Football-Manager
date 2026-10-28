-- Programmes collectifs et individuels, rapports de progression hebdomadaires.

CREATE TABLE `training_plans` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `save_id` BIGINT UNSIGNED NOT NULL,
    `club_id` BIGINT UNSIGNED NOT NULL,
    `staff_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `week_start` DATE NOT NULL,
    `focus` ENUM('physical', 'technical', 'tactical', 'defense', 'attack', 'goalkeeping', 'recovery') NOT NULL,
    `intensity` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    `notes` TEXT NULL,
    `applied_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_training_plans_club_week` (`club_id`, `week_start`),
    KEY `idx_training_plans_save_week` (`save_id`, `week_start`),
    KEY `idx_training_plans_staff` (`staff_id`),
    CONSTRAINT `fk_training_plans_save` FOREIGN KEY (`save_id`) REFERENCES `saves` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_training_plans_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_training_plans_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_plan_players` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `save_id` BIGINT UNSIGNED NOT NULL,
    `training_plan_id` BIGINT UNSIGNED NOT NULL,
    `player_id` BIGINT UNSIGNED NOT NULL,
    `target_attribute` VARCHAR(50) NOT NULL,
    `intensity` ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_training_plan_players_player` (`training_plan_id`, `player_id`),
    KEY `idx_training_plan_players_save` (`save_id`),
    KEY `idx_training_plan_players_player` (`player_id`),
    CONSTRAINT `fk_training_plan_players_save` FOREIGN KEY (`save_id`) REFERENCES `saves` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_training_plan_players_plan` FOREIGN KEY (`training_plan_id`) REFERENCES `training_plans` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_training_plan_players_player` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_reports` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `save_id` BIGINT UNSIGNED NOT NULL,
    `training_plan_id` BIGINT UNSIGNED NOT NULL,
    `club_id` BIGINT UNSIGNED NOT NULL,
    `week_start` DATE NOT NULL,
    `summary` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_training_reports_plan` (`training_plan_id`),
    KEY `idx_training_reports_save_club_week` (`save_id`, `club_id`, `week_start`),
    KEY `idx_training_reports_club` (`club_id`),
    CONSTRAINT `fk_training_reports_save` FOREIGN KEY (`save_id`) REFERENCES `saves` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_training_reports_plan` FOREIGN KEY (`training_plan_id`) REFERENCES `training_plans` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_training_reports_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `training_report_entries` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `save_id` BIGINT UNSIGNED NOT NULL,
    `training_report_id` BIGINT UNSIGNED NOT NULL,
    `player_id` BIGINT UNSIGNED NOT NULL,
    `attribute` VARCHAR(50) NULL DEFAULT NULL,
    `previous_value` TINYINT UNSIGNED NULL DEFAULT NULL,
    `new_value` TINYINT UNSIGNED NULL DEFAULT NULL,
    `fatigue_delta` SMALLINT NOT NULL DEFAULT 0,
    `morale_delta` SMALLINT NOT NULL DEFAULT 0,
    `injury_occurred` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_training_report_entries_report` (`training_report_id`),
    KEY `idx_training_report_entries_save_player` (`save_id`, `player_id`),
    KEY `idx_training_report_entries_player` (`player_id`),
    CONSTRAINT `fk_training_report_entries_save` FOREIGN KEY (`save_id`) REFERENCES `saves` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_training_report_entries_report` FOREIGN KEY (`training_report_id`) REFERENCES `training_reports` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_training_report_entries_player` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

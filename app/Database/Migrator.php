<?php

declare(strict_types=1);

namespace App\Database;

use RuntimeException;
use Throwable;

/**
 * Exécute une seule fois chaque migration SQL du noyau et des modules actifs.
 */
final class Migrator
{
    private const REPOSITORY = 'schema_migrations';

    /** @param array<string, string> $modulePaths */
    public function __construct(
        private readonly Database $database,
        private readonly string $corePath,
        private readonly array $modulePaths
    ) {
    }

    /** @return list<array{migration: string, batch: int}> */
    public function migrate(): array
    {
        $this->ensureRepository();
        $ran = array_column($this->database->select(
            'SELECT `migration` FROM `' . self::REPOSITORY . '`'
        ), 'migration');
        $ran = array_fill_keys($ran, true);

        $row = $this->database->selectOne(
            'SELECT COALESCE(MAX(`batch`), 0) + 1 AS `next_batch` FROM `' . self::REPOSITORY . '`'
        );
        $batch = (int) ($row['next_batch'] ?? 1);
        $executed = [];

        foreach ($this->migrationFiles() as $name => $file) {
            if (isset($ran[$name])) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException(sprintf('Impossible de lire la migration "%s".', $name));
            }

            try {
                // Les scripts sont des fichiers de confiance versionnés, jamais des entrées utilisateur.
                foreach ($this->splitStatements($sql) as $statement) {
                    $this->database->pdo()->exec($statement);
                }

                $this->database->insert(
                    'INSERT INTO `' . self::REPOSITORY . '` (`migration`, `batch`) VALUES (:migration, :batch)',
                    ['migration' => $name, 'batch' => $batch]
                );
                $executed[] = ['migration' => $name, 'batch' => $batch];
            } catch (Throwable $exception) {
                throw new RuntimeException(sprintf(
                    'Échec de la migration "%s" : %s. Vérifiez les instructions déjà exécutées avant de relancer.',
                    $name,
                    $exception->getMessage()
                ), 0, $exception);
            }
        }

        return $executed;
    }

    /** @return list<array{migration: string, ran: bool, batch: int|null}> */
    public function status(): array
    {
        $this->ensureRepository();
        $rows = $this->database->select(
            'SELECT `migration`, `batch` FROM `' . self::REPOSITORY . '`'
        );
        $ran = [];
        foreach ($rows as $row) {
            $ran[(string) $row['migration']] = (int) $row['batch'];
        }

        $status = [];
        foreach (array_keys($this->migrationFiles()) as $name) {
            $status[] = [
                'migration' => $name,
                'ran' => array_key_exists($name, $ran),
                'batch' => $ran[$name] ?? null,
            ];
        }
        return $status;
    }

    /** @return array<string, string> */
    private function migrationFiles(): array
    {
        $migrations = [];
        $coreFiles = glob(rtrim($this->corePath, DIRECTORY_SEPARATOR) . '/*.sql') ?: [];
        sort($coreFiles);

        foreach ($coreFiles as $file) {
            $migrations[basename($file)] = $file;
        }

        $modulePaths = $this->modulePaths;
        ksort($modulePaths);
        foreach ($modulePaths as $module => $path) {
            $files = glob(rtrim($path, DIRECTORY_SEPARATOR) . '/*.sql') ?: [];
            sort($files);
            foreach ($files as $file) {
                $name = $module . '/' . basename($file);
                if (isset($migrations[$name])) {
                    throw new RuntimeException(sprintf('Migration dupliquée : "%s".', $name));
                }
                $migrations[$name] = $file;
            }
        }

        return $migrations;
    }

    private function ensureRepository(): void
    {
        $this->database->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS `' . self::REPOSITORY . '` ('
            . '`migration` VARCHAR(191) NOT NULL PRIMARY KEY, '
            . '`batch` INT UNSIGNED NOT NULL, '
            . '`executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Découpe un script sans casser les semicolon présents dans les littéraux.
     * Les procédures stockées avec DELIMITER sont volontairement hors périmètre.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $statement = '';
        $length = strlen($sql);
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $statement .= "\n";
                }
                continue;
            }

            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }
                continue;
            }

            if ($quote !== null) {
                $statement .= $char;

                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $statement .= $next;
                    $index++;
                    continue;
                }

                if ($char === $quote) {
                    if ($next === $quote) {
                        $statement .= $next;
                        $index++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($char === '#' || ($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2])))) {
                $lineComment = true;
                if ($char === '-') {
                    $index++;
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $index++;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $statement .= $char;
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($statement);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $statement = '';
                continue;
            }

            $statement .= $char;
        }

        if ($quote !== null || $blockComment) {
            throw new RuntimeException('Script SQL mal terminé (guillemet ou commentaire non fermé).');
        }

        $trimmed = trim($statement);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}

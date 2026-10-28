<?php

declare(strict_types=1);

namespace App\Core;

use ErrorException;
use Throwable;

/**
 * Journalise les erreurs sans exposer de détails sensibles en production.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly bool $debug,
        private readonly string $logPath
    ) {
    }

    public function register(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(fn (Throwable $exception) => $this->handle($exception));

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->handle(new ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                ));
            }
        });
    }

    public function handle(Throwable $exception): void
    {
        $this->report($exception);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, sprintf("[%s] %s in %s:%d\n", $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
            if ($this->debug) {
                fwrite(STDERR, $exception->getTraceAsString() . PHP_EOL);
            }
            return;
        }

        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $wantsJson = str_contains($accept, 'application/json');

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: ' . ($wantsJson ? 'application/json' : 'text/html') . '; charset=UTF-8');
        }

        if ($wantsJson) {
            $payload = ['message' => 'Erreur interne du serveur.'];
            if ($this->debug) {
                $payload['exception'] = $exception::class;
                $payload['details'] = $exception->getMessage();
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $message = $this->debug
            ? $exception::class . ' : ' . $exception->getMessage() . "\n" . $exception->getTraceAsString()
            : 'Une erreur interne est survenue. Veuillez réessayer ultérieurement.';

        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Erreur</title>'
            . '<body style="background:#101827;color:#e5e7eb;font-family:sans-serif;padding:2rem">'
            . '<h1>Erreur applicative</h1><pre style="white-space:pre-wrap">'
            . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</pre></body></html>';
    }

    /** Journalise aussi les exceptions interceptées par les contrôleurs. */
    public function report(Throwable $exception): void
    {
        if (!is_dir($this->logPath)) {
            @mkdir($this->logPath, 0775, true);
        }

        $line = sprintf(
            "[%s] %s: %s in %s:%d\n%s\n\n",
            date('c'),
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        @error_log($line, 3, rtrim($this->logPath, DIRECTORY_SEPARATOR) . '/app.log');
    }
}

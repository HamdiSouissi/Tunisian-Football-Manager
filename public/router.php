<?php

declare(strict_types=1);

// Routeur du serveur PHP intégré : les fichiers publics restent servis directement.
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$publicPath = realpath(__DIR__);
$file = realpath(__DIR__ . $path);

// Ne jamais considérer un chemin résolu hors de public/ comme une ressource publique.
if ($path !== '/' && $publicPath !== false && $file !== false
    && str_starts_with($file, $publicPath . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';

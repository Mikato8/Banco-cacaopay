<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (!Config::bool('APP_INSTALL', false)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Instalador deshabilitado. Configura APP_INSTALL=true en .env\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = Database::pdo();

    $schema = (string) file_get_contents(__DIR__ . '/../db/schema.sql');
    $seed = (string) file_get_contents(__DIR__ . '/../db/seed.sql');

    foreach (explode(';', $schema . "\n" . $seed) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }

    echo "OK: esquema y datos demo instalados.\n";
    echo "Borra install.php y pon APP_INSTALL=false en .env\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage() . "\n";
}

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
    $migrate = (string) file_get_contents(__DIR__ . '/../db/migrate.sql');

    $fiscalPath = __DIR__ . '/../db/fiscal.sql';
    $fiscal = is_file($fiscalPath) ? (string) file_get_contents($fiscalPath) : '';

    foreach (explode(';', $schema . "\n" . $seed . "\n" . $migrate . "\n" . $fiscal) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }

    // Fila única de configuración fiscal (id=1) con los valores FISCAL_* del .env.
    Fiscal::ensureSettings();

    echo "OK: esquema y datos demo instalados (incluye módulo fiscal SAT).\n";
    echo "Borra install.php y pon APP_INSTALL=false en .env\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage() . "\n";
}

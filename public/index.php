<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rtrim((string) $path, '/') ?: '/';
$method = Http::method();

header('Access-Control-Allow-Origin: ' . Config::get('CLIENT_ORIGIN', '*'));
header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Max-Age: 86400');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!str_starts_with($path, '/api/')) {
    serveStatic($path);
}

try {
    route($method, $path);
} catch (AppException $e) {
    Response::error($e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log((string) $e);
    Response::error('Error interno', 500);
}

function serveStatic(string $path): never
{
    $file = $path === '/' ? '/index.html' : $path;
    $publicDir = realpath(__DIR__);
    $real = realpath(__DIR__ . $file);

    if ($publicDir === false || $real === false || !str_starts_with($real, $publicDir) || !is_file($real)) {
        Response::error('No encontrado', 404);
    }

    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
    ];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($real);
    exit;
}

function route(string $method, string $path): never
{
    if ($method === 'GET' && $path === '/api/health') {
        Response::json([
            'ok' => true,
            'service' => 'banco-cacaopay',
            'cacao_per_fiat' => (float) Config::get('CACAO_PER_FIAT', '2'),
            'fiat_currency' => Config::get('FIAT_CURRENCY', 'PEN'),
        ]);
    }

    if ($method === 'POST' && $path === '/api/auth/register') {
        Auth::register(Http::body());
    }
    if ($method === 'POST' && $path === '/api/auth/login') {
        Auth::login(Http::body());
    }
    if ($method === 'GET' && $path === '/api/auth/me') {
        Response::json(['user' => Auth::publicUser(Auth::requireAuth())]);
    }

    if ($method === 'GET' && $path === '/api/wallet/balance') {
        Wallet::balance(Auth::requireAuth());
    }
    if ($method === 'GET' && $path === '/api/wallet/ledger') {
        Wallet::ledger(Auth::requireAuth());
    }
    if ($method === 'POST' && $path === '/api/wallet/deposit') {
        Wallet::deposit(Auth::requireAuth(), Http::body());
    }
    if ($method === 'POST' && $path === '/api/wallet/transfer') {
        Wallet::transfer(Auth::requireAuth(), Http::body());
    }

    if ($method === 'GET' && $path === '/api/products') {
        Products::list();
    }
    if ($method === 'POST' && $path === '/api/products') {
        Products::create(Auth::requireAuth(), Http::body());
    }
    if ($method === 'POST' && $path === '/api/products/redeem') {
        Products::redeem(Auth::requireAuth(), Http::body());
    }

    Response::error('Ruta no encontrada', 404);
}

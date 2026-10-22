<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $url = Config::get('DATABASE_URL');
        if ($url !== '' && str_starts_with($url, 'mysql')) {
            $parts = parse_url($url);
            $host = $parts['host'] ?? 'localhost';
            $port = (int) ($parts['port'] ?? 3306);
            $db = ltrim($parts['path'] ?? '', '/');
            $user = $parts['user'] ?? '';
            $pass = $parts['pass'] ?? '';
        } else {
            $host = Config::get('DB_HOST', 'localhost');
            $port = Config::int('DB_PORT', 3306);
            $db = Config::get('DB_NAME', 'cacaopay');
            $user = Config::get('DB_USER', 'root');
            $pass = Config::get('DB_PASSWORD');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db);

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$pdo;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

<?php

declare(strict_types=1);

final class Auth
{
    public static function register(array $body): never
    {
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $name = trim((string) ($body['name'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $role = strtoupper(trim((string) ($body['role'] ?? 'CLIENT')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AppException(400, 'Email inválido');
        }
        $len = mb_strlen($name);
        if ($len < 2 || $len > 80) {
            throw new AppException(400, 'Nombre debe tener entre 2 y 80 caracteres');
        }
        if (strlen($password) < 6 || strlen($password) > 100) {
            throw new AppException(400, 'Contraseña debe tener entre 6 y 100 caracteres');
        }
        if (!in_array($role, ['CLIENT', 'SELLER'], true)) {
            $role = 'CLIENT';
        }

        $pdo = Database::pdo();

        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            throw new AppException(409, 'El correo ya está registrado');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare(
            'INSERT INTO users (email, name, password_hash, role)
             VALUES (?, ?, ?, ?)
             RETURNING id, email, name, role, kyc_status, created_at'
        );
        $stmt->execute([$email, $name, $hash, $role]);
        $user = $stmt->fetch();

        $acct = $pdo->prepare("INSERT INTO accounts (user_id, kind, currency) VALUES (?, 'USER', 'CACAO')");
        $acct->execute([$user['id']]);

        Response::json([
            'token' => Jwt::encode(['sub' => $user['id'], 'role' => $role, 'exp' => time() + 604800]),
            'user' => self::publicUser($user),
        ], 201);
    }

    public static function login(array $body): never
    {
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            throw new AppException(401, 'Credenciales inválidas');
        }

        Response::json([
            'token' => Jwt::encode(['sub' => $user['id'], 'role' => $user['role'], 'exp' => time() + 604800]),
            'user' => self::publicUser($user),
        ]);
    }

    /** @return array<string,mixed> */
    public static function requireAuth(): array
    {
        $token = Http::bearerToken();
        $payload = $token !== null ? Jwt::decode($token) : null;
        if (!$payload || empty($payload['sub'])) {
            throw new AppException(401, 'No autenticado');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, email, name, role, kyc_status, created_at FROM users WHERE id = ?');
        $stmt->execute([$payload['sub']]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new AppException(404, 'Usuario no encontrado');
        }
        return $user;
    }

    /** @param string[] $roles */
    public static function requireRole(array $user, array $roles): void
    {
        if (!in_array($user['role'], $roles, true)) {
            throw new AppException(403, 'Rol insuficiente');
        }
    }

    /** @param array<string,mixed> $u */
    public static function publicUser(array $u): array
    {
        return [
            'id' => $u['id'],
            'email' => $u['email'],
            'name' => $u['name'],
            'role' => $u['role'],
            'kyc_status' => $u['kyc_status'],
            'created_at' => $u['created_at'],
        ];
    }
}

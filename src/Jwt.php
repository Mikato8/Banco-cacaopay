<?php

declare(strict_types=1);

final class Jwt
{
    public static function encode(array $payload): string
    {
        $secret = Config::get('JWT_SECRET', 'dev-secret-change-me');
        $header = self::b64((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::b64((string) json_encode($payload));
        $sig = self::b64(hash_hmac('sha256', "$header.$body", $secret, true));
        return "$header.$body.$sig";
    }

    public static function decode(string $token): ?array
    {
        $secret = Config::get('JWT_SECRET', 'dev-secret-change-me');
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $body, $sig] = $parts;
        $expected = self::b64(hash_hmac('sha256', "$header.$body", $secret, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $payload = json_decode(self::unb64($body), true);
        if (!is_array($payload)) {
            return null;
        }
        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return null;
        }
        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}

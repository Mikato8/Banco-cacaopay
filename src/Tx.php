<?php

declare(strict_types=1);

/**
 * Transacciones idempotentes: un Idempotency-Key (UUID) se procesa una sola
 * vez. Si el cliente reenvía la misma petición, se devuelve el resultado
 * original en lugar de cobrar dos veces.
 */
final class Tx
{
    /** @return array{0: PDO, 1: string} */
    public static function begin(string $kind, ?string $idempotencyKey): array
    {
        $pdo = Database::pdo();

        $key = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? strtolower(trim($idempotencyKey))
            : Database::uuid();

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key)) {
            throw new AppException(400, 'Idempotency-Key debe ser un UUID');
        }

        $existing = self::lookup($pdo, $key);
        if ($existing !== null) {
            if ($existing['result'] !== null) {
                $payload = json_decode($existing['result'], true);
                Response::json(is_array($payload) ? $payload : [], 200);
            }
            throw new AppException(409, 'Transacción en progreso');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO transactions (idempotency_key, kind) VALUES (?, ?) RETURNING id');
            $stmt->execute([$key, $kind]);
            $txnId = (string) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23505') {
                $existing = self::lookup($pdo, $key);
                if ($existing !== null && $existing['result'] !== null) {
                    $payload = json_decode($existing['result'], true);
                    Response::json(is_array($payload) ? $payload : [], 200);
                }
                throw new AppException(409, 'Transacción duplicada');
            }
            throw $e;
        }

        return [$pdo, $txnId];
    }

    public static function finish(PDO $pdo, string $txnId, array $payload, int $status = 201): never
    {
        $stmt = $pdo->prepare('UPDATE transactions SET result = ?::jsonb WHERE id = ?');
        $stmt->execute([(string) json_encode($payload, JSON_UNESCAPED_UNICODE), $txnId]);
        $pdo->commit();
        Response::json($payload, $status);
    }

    /** @return array{id: string, result: ?string}|null */
    private static function lookup(PDO $pdo, string $key): ?array
    {
        $stmt = $pdo->prepare('SELECT id, result FROM transactions WHERE idempotency_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}

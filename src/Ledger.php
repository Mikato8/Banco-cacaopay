<?php

declare(strict_types=1);

/**
 * Libro mayor de doble entrada (double-entry ledger).
 *
 * Invariante de conservación del dinero: la suma de saldos de TODAS las
 * cuentas siempre es 0. Cada movimiento inserta una fila con un débito
 * (cuenta que pierde) y un crédito (cuenta que gana) por el mismo monto.
 */
final class Ledger
{
    public static function systemCacaoAccount(PDO $pdo): string
    {
        $stmt = $pdo->query("SELECT id FROM accounts WHERE kind = 'SYSTEM' AND currency = 'CACAO'");
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException(500, 'Cuenta del sistema no encontrada');
        }
        return $row['id'];
    }

    /** @return array<string,mixed> */
    public static function lockUserCacaoAccount(PDO $pdo, string $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, user_id, kind, currency, balance
             FROM accounts
             WHERE user_id = ? AND currency = 'CACAO'
             FOR UPDATE"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AppException(404, 'Cuenta de cacao del usuario no encontrada');
        }
        return $row;
    }

    public static function balanceOf(PDO $pdo, string $userId): string
    {
        $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE user_id = ? AND currency = 'CACAO'");
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        return $value === false ? '0' : (string) $value;
    }

    public static function addEntry(
        PDO $pdo,
        string $transactionId,
        string $type,
        string $debitAccountId,
        string $creditAccountId,
        string $amount,
        array $meta = []
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO ledger_entries (transaction_id, entry_type, debit_account_id, credit_account_id, amount, meta)
             VALUES (?, ?, ?, ?, ?::numeric, ?::jsonb)'
        );
        $stmt->execute([
            $transactionId,
            $type,
            $debitAccountId,
            $creditAccountId,
            $amount,
            (string) json_encode($meta, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function updateBalance(PDO $pdo, string $accountId, string $delta): void
    {
        $stmt = $pdo->prepare('UPDATE accounts SET balance = balance + (?::numeric) WHERE id = ?');
        $stmt->execute([$delta, $accountId]);
    }
}

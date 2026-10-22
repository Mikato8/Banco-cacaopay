<?php

declare(strict_types=1);

final class Wallet
{
    public static function balance(array $user): never
    {
        $pdo = Database::pdo();
        Response::json([
            'cacao_balance' => (float) Ledger::balanceOf($pdo, $user['id']),
            'cacao_per_fiat' => (float) Config::get('CACAO_PER_FIAT', '2'),
            'fiat_currency' => Config::get('FIAT_CURRENCY', 'PEN'),
            'symbol' => '🍫',
            'unit' => 'cacao',
        ]);
    }

    public static function ledger(array $user): never
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT e.id, e.entry_type, e.amount, e.meta, e.created_at,
                    d.user_id AS from_user_id, c.user_id AS to_user_id
             FROM ledger_entries e
             JOIN accounts d ON d.id = e.debit_account_id
             JOIN accounts c ON c.id = e.credit_account_id
             WHERE d.user_id = ? OR c.user_id = ?
             ORDER BY e.created_at DESC
             LIMIT 100"
        );
        $stmt->execute([$user['id'], $user['id']]);
        $rows = $stmt->fetchAll();

        $entries = array_map(function (array $r) use ($user) {
            $meta = json_decode($r['meta'] ?? '{}', true);
            return [
                'id' => $r['id'],
                'type' => $r['entry_type'],
                'amount' => (float) $r['amount'],
                'direction' => ($r['to_user_id'] === $user['id']) ? 'IN' : 'OUT',
                'meta' => is_array($meta) ? $meta : [],
                'created_at' => $r['created_at'],
            ];
        }, $rows);

        Response::json(['entries' => $entries]);
    }

    public static function deposit(array $user, array $body): never
    {
        $amountFiat = round((float) ($body['amountFiat'] ?? 0), 2);
        $note = trim((string) ($body['note'] ?? ''));

        if ($amountFiat < 1.0) {
            throw new AppException(400, 'Monto demasiado bajo (mínimo 1.00)');
        }

        $rate = (float) Config::get('CACAO_PER_FIAT', '2');
        $cacao = floor($amountFiat * $rate * 100) / 100;
        $cacaoStr = number_format($cacao, 2, '.', '');

        $pdo = Database::pdo();
        [$pdo, $txnId] = Tx::begin('DEPOSIT', Http::idempotencyKey());

        try {
            $system = Ledger::systemCacaoAccount($pdo);
            $account = Ledger::lockUserCacaoAccount($pdo, $user['id']);

            $meta = ['amountFiat' => number_format($amountFiat, 2, '.', ''), 'note' => $note];
            Ledger::addEntry($pdo, $txnId, 'DEPOSIT', $system, $account['id'], $cacaoStr, $meta);
            Ledger::updateBalance($pdo, $system, '-' . $cacaoStr);
            Ledger::updateBalance($pdo, $account['id'], $cacaoStr);

            $payload = [
                'cacao_credited' => $cacao,
                'amount_fiat' => $amountFiat,
                'cacao_balance' => (float) Ledger::balanceOf($pdo, $user['id']),
                'entry' => ['type' => 'DEPOSIT', 'amount' => $cacao],
            ];
            Tx::finish($pdo, $txnId, $payload, 201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function transfer(array $user, array $body): never
    {
        $toEmail = strtolower(trim((string) ($body['toEmail'] ?? '')));
        $amount = round((float) ($body['amountCacao'] ?? 0), 2);
        $note = trim((string) ($body['note'] ?? ''));

        if ($amount <= 0) {
            throw new AppException(400, 'Monto inválido');
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new AppException(400, 'Email del destinatario inválido');
        }

        $amountStr = number_format($amount, 2, '.', '');
        $pdo = Database::pdo();
        [$pdo, $txnId] = Tx::begin('TRANSFER', Http::idempotencyKey());

        try {
            $toStmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ?');
            $toStmt->execute([$toEmail]);
            $toUser = $toStmt->fetch();
            if (!$toUser) {
                throw new AppException(404, 'Destinatario no encontrado');
            }
            if ($toUser['id'] === $user['id']) {
                throw new AppException(400, 'No puedes transferirte a ti mismo');
            }

            $from = Ledger::lockUserCacaoAccount($pdo, $user['id']);
            if ((float) $from['balance'] < $amount) {
                throw new AppException(400, 'Saldo de cacao insuficiente');
            }
            $to = Ledger::lockUserCacaoAccount($pdo, $toUser['id']);

            $meta = ['toEmail' => $toEmail, 'note' => $note];
            Ledger::addEntry($pdo, $txnId, 'TRANSFER_OUT', $from['id'], $to['id'], $amountStr, $meta);
            Ledger::updateBalance($pdo, $from['id'], '-' . $amountStr);
            Ledger::updateBalance($pdo, $to['id'], $amountStr);

            $payload = [
                'cacao_balance' => (float) Ledger::balanceOf($pdo, $user['id']),
                'sent' => $amount,
                'to' => ['email' => $toEmail, 'name' => $toUser['name']],
            ];
            Tx::finish($pdo, $txnId, $payload, 201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

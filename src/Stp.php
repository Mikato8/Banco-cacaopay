<?php

declare(strict_types=1);

/**
 * Módulo STP (Sistema de Transferencias y Pagos) — simulado.
 * Entrada: el banco recibe MXN por STP y acredita cacao al cliente (1 cacao = 1 MXN).
 * Salida (inversa): el cliente retira cacao y el banco lo convierte a MXN para
 * transferirlo, vía una procesadora de pago, a la cuenta bancaria (CLABE) de un destinatario.
 */
final class Stp
{
    public static function deposit(array $user, array $body): never
    {
        $amountMxn = round((float) ($body['amountMxn'] ?? 0), 2);
        $reference = trim((string) ($body['reference'] ?? ''));

        if ($amountMxn < 1.0) {
            throw new AppException(400, 'Monto demasiado bajo (mínimo 1.00)');
        }

        $rate = (float) Config::get('CACAO_PER_FIAT', '1');
        $cacao = floor($amountMxn * $rate * 100) / 100;
        $cacaoStr = number_format($cacao, 2, '.', '');
        $mxnStr = number_format($amountMxn, 2, '.', '');

        $pdo = Database::pdo();
        [$pdo, $txnId] = Tx::begin('STP_DEPOSIT', Http::idempotencyKey());

        try {
            $system = Ledger::systemCacaoAccount($pdo);
            $account = Ledger::lockUserCacaoAccount($pdo, $user['id']);

            Ledger::addEntry($pdo, $txnId, 'DEPOSIT', $system, $account['id'], $cacaoStr, [
                'via' => 'STP', 'reference' => $reference, 'amountMxn' => $mxnStr,
            ]);
            Ledger::updateBalance($pdo, $system, '-' . $cacaoStr);
            Ledger::updateBalance($pdo, $account['id'], $cacaoStr);

            $id = Database::uuid();
            $stmt = $pdo->prepare(
                "INSERT INTO stp_transfers (id, user_id, direction, amount_mxn, amount_cacao, reference, transaction_id)
                 VALUES (?, ?, 'IN', ?, ?, ?, ?)"
            );
            $stmt->execute([$id, $user['id'], $mxnStr, $cacaoStr, $reference, $txnId]);

            $payload = [
                'cacao_credited' => $cacao,
                'amount_mxn' => $amountMxn,
                'cacao_balance' => (float) Ledger::balanceOf($pdo, $user['id']),
                'transfer' => ['id' => $id, 'direction' => 'IN', 'reference' => $reference],
            ];
            Tx::finish($pdo, $txnId, $payload, 201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function withdraw(array $user, array $body): never
    {
        $amountCacao = round((float) ($body['amountCacao'] ?? 0), 2);
        $clabe = trim((string) ($body['clabe'] ?? ''));
        $bankName = trim((string) ($body['bankName'] ?? ''));
        $beneficiary = trim((string) ($body['beneficiary'] ?? ''));

        if ($amountCacao <= 0) {
            throw new AppException(400, 'Monto inválido');
        }
        if (!preg_match('/^\d{18}$/', $clabe)) {
            throw new AppException(400, 'CLABE inválida (debe tener 18 dígitos)');
        }
        if ($beneficiary === '') {
            throw new AppException(400, 'Nombre del beneficiario requerido');
        }

        $rate = (float) Config::get('CACAO_PER_FIAT', '1');
        $mxn = round($amountCacao / $rate, 2);
        $cacaoStr = number_format($amountCacao, 2, '.', '');
        $mxnStr = number_format($mxn, 2, '.', '');

        $pdo = Database::pdo();
        [$pdo, $txnId] = Tx::begin('STP_WITHDRAW', Http::idempotencyKey());

        try {
            $account = Ledger::lockUserCacaoAccount($pdo, $user['id']);
            if ((float) $account['balance'] < $amountCacao) {
                throw new AppException(400, 'Saldo de cacao insuficiente');
            }
            $system = Ledger::systemCacaoAccount($pdo);

            Ledger::addEntry($pdo, $txnId, 'WITHDRAW', $account['id'], $system, $cacaoStr, [
                'via' => 'STP', 'clabe' => $clabe, 'beneficiary' => $beneficiary, 'amountMxn' => $mxnStr,
            ]);
            Ledger::updateBalance($pdo, $account['id'], '-' . $cacaoStr);
            Ledger::updateBalance($pdo, $system, $cacaoStr);

            $id = Database::uuid();
            $stmt = $pdo->prepare(
                "INSERT INTO stp_transfers (id, user_id, direction, amount_mxn, amount_cacao, account_number, bank_name, beneficiary, transaction_id)
                 VALUES (?, ?, 'OUT', ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$id, $user['id'], $mxnStr, $cacaoStr, $clabe, $bankName, $beneficiary, $txnId]);

            $payload = [
                'cacao_debited' => $amountCacao,
                'amount_mxn' => $mxn,
                'cacao_balance' => (float) Ledger::balanceOf($pdo, $user['id']),
                'transfer' => ['id' => $id, 'direction' => 'OUT', 'clabe' => $clabe, 'beneficiary' => $beneficiary],
            ];
            Tx::finish($pdo, $txnId, $payload, 201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function transfers(array $user): never
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT id, direction, amount_mxn, amount_cacao, account_number, bank_name, beneficiary, reference, status, created_at
             FROM stp_transfers
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 100"
        );
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll();

        $transfers = array_map(function (array $r) {
            return [
                'id' => $r['id'],
                'direction' => $r['direction'],
                'amount_mxn' => (float) $r['amount_mxn'],
                'amount_cacao' => (float) $r['amount_cacao'],
                'account_number' => $r['account_number'],
                'bank_name' => $r['bank_name'],
                'beneficiary' => $r['beneficiary'],
                'reference' => $r['reference'],
                'status' => $r['status'],
                'created_at' => $r['created_at'],
            ];
        }, $rows);

        Response::json(['transfers' => $transfers]);
    }
}

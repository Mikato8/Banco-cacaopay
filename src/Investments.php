<?php

declare(strict_types=1);

/**
 * Fondos de inversión en cacao: el cliente invierte cacao y genera interés
 * simple prorrateado por día (interés = principal × tasa anual × días / 365).
 */
final class Investments
{
    public static function invest(array $user, array $body): never
    {
        $amount = round((float) ($body['amountCacao'] ?? 0), 2);
        $rate = isset($body['annualRate']) && $body['annualRate'] !== '' && $body['annualRate'] !== null
            ? (float) $body['annualRate']
            : (float) Config::get('INVEST_ANNUAL_RATE', '0.05');

        if ($amount <= 0) {
            throw new AppException(400, 'Monto inválido');
        }
        if ($rate <= 0 || $rate > 1) {
            throw new AppException(400, 'Tasa anual inválida (entre 0 y 1)');
        }

        $amountStr = number_format($amount, 2, '.', '');
        $rateStr = number_format($rate, 5, '.', '');

        $pdo = Database::pdo();
        [$pdo, $txnId] = Tx::begin('INVEST', Http::idempotencyKey());

        try {
            $account = Ledger::lockUserCacaoAccount($pdo, $user['id']);
            if ((float) $account['balance'] < $amount) {
                throw new AppException(400, 'Saldo de cacao insuficiente');
            }
            $system = Ledger::systemCacaoAccount($pdo);

            Ledger::addEntry($pdo, $txnId, 'INVEST', $account['id'], $system, $amountStr, ['annualRate' => $rate]);
            Ledger::updateBalance($pdo, $account['id'], '-' . $amountStr);
            Ledger::updateBalance($pdo, $system, $amountStr);

            $id = Database::uuid();
            $stmt = $pdo->prepare(
                'INSERT INTO investments (id, user_id, principal_cacao, annual_rate) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$id, $user['id'], $amountStr, $rateStr]);

            $payload = [
                'investment' => ['id' => $id, 'principal_cacao' => $amount, 'annual_rate' => $rate],
                'cacao_balance' => (float) Ledger::balanceOf($pdo, $user['id']),
            ];
            Tx::finish($pdo, $txnId, $payload, 201);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function list(array $user): never
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "SELECT id, principal_cacao, annual_rate, status, created_at,
                    TIMESTAMPDIFF(DAY, created_at, NOW()) AS days_elapsed
             FROM investments
             WHERE user_id = ? AND status = 'ACTIVE'
             ORDER BY created_at DESC"
        );
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll();

        $investments = array_map(function (array $r) {
            $principal = (float) $r['principal_cacao'];
            $rate = (float) $r['annual_rate'];
            $days = (int) $r['days_elapsed'];
            $interest = round($principal * $rate * $days / 365, 2);
            return [
                'id' => $r['id'],
                'principal_cacao' => $principal,
                'annual_rate' => $rate,
                'days_elapsed' => $days,
                'interest_accrued' => $interest,
                'total' => round($principal + $interest, 2),
                'created_at' => $r['created_at'],
            ];
        }, $rows);

        Response::json(['investments' => $investments]);
    }

    public static function redeem(array $user, array $body): never
    {
        $investmentId = (string) ($body['investmentId'] ?? '');
        if ($investmentId === '') {
            throw new AppException(400, 'investmentId requerido');
        }

        $pdo = Database::pdo();
        [$pdo, $txnId] = Tx::begin('INVEST_REDEEM', Http::idempotencyKey());

        try {
            $stmt = $pdo->prepare(
                "SELECT id, user_id, principal_cacao, annual_rate, status,
                        TIMESTAMPDIFF(DAY, created_at, NOW()) AS days_elapsed
                 FROM investments WHERE id = ? FOR UPDATE"
            );
            $stmt->execute([$investmentId]);
            $inv = $stmt->fetch();
            if (!$inv || $inv['user_id'] !== $user['id']) {
                throw new AppException(404, 'Inversión no encontrada');
            }
            if ($inv['status'] !== 'ACTIVE') {
                throw new AppException(400, 'La inversión ya fue redimida');
            }

            $principal = (float) $inv['principal_cacao'];
            $rate = (float) $inv['annual_rate'];
            $days = (int) $inv['days_elapsed'];
            $interest = round($principal * $rate * $days / 365, 2);
            $total = round($principal + $interest, 2);
            $totalStr = number_format($total, 2, '.', '');

            $system = Ledger::systemCacaoAccount($pdo);
            $account = Ledger::lockUserCacaoAccount($pdo, $user['id']);

            Ledger::addEntry($pdo, $txnId, 'INVEST_REDEEM', $system, $account['id'], $totalStr, [
                'investmentId' => $investmentId, 'principal' => $principal, 'interest' => $interest,
            ]);
            Ledger::updateBalance($pdo, $system, '-' . $totalStr);
            Ledger::updateBalance($pdo, $account['id'], $totalStr);

            $upd = $pdo->prepare("UPDATE investments SET status = 'REDEEMED', redeemed_at = NOW() WHERE id = ?");
            $upd->execute([$investmentId]);

            $payload = [
                'cacao_balance' => (float) Ledger::balanceOf($pdo, $user['id']),
                'principal' => $principal,
                'interest' => $interest,
                'total' => $total,
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

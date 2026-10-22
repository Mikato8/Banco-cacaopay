<?php

declare(strict_types=1);

/**
 * Préstamos en cacao: el banco presta cacao al cliente; el cliente lo paga
 * con interés simple prorrateado por día (interés = principal × tasa anual × días / 365).
 */
final class Loans
{
    public static function request(array $user, array $body): never
    {
        $amount = round((float) ($body['amountCacao'] ?? 0), 2);
        $termDays = (int) ($body['termDays'] ?? 0);
        if ($termDays <= 0) {
            $termDays = Config::int('LOAN_TERM_DAYS', 30);
        }
        $rate = (float) Config::get('LOAN_ANNUAL_RATE', '0.12');

        if ($amount <= 0) {
            throw new AppException(400, 'Monto inválido');
        }
        if ($termDays < 1 || $termDays > 3650) {
            throw new AppException(400, 'Plazo inválido');
        }

        $amountStr = number_format($amount, 2, '.', '');
        $rateStr = number_format($rate, 5, '.', '');

        $pdo = Database::pdo();
        [$pdo, $txnId] = Tx::begin('LOAN', Http::idempotencyKey());

        try {
            $system = Ledger::systemCacaoAccount($pdo);
            $account = Ledger::lockUserCacaoAccount($pdo, $user['id']);

            Ledger::addEntry($pdo, $txnId, 'LOAN', $system, $account['id'], $amountStr, [
                'annualRate' => $rate, 'termDays' => $termDays,
            ]);
            Ledger::updateBalance($pdo, $system, '-' . $amountStr);
            Ledger::updateBalance($pdo, $account['id'], $amountStr);

            $id = Database::uuid();
            $stmt = $pdo->prepare(
                'INSERT INTO loans (id, user_id, principal_cacao, annual_rate, term_days, due_at)
                 VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
            );
            $stmt->execute([$id, $user['id'], $amountStr, $rateStr, $termDays, $termDays]);

            $payload = [
                'loan' => [
                    'id' => $id,
                    'principal_cacao' => $amount,
                    'annual_rate' => $rate,
                    'term_days' => $termDays,
                ],
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
            "SELECT id, principal_cacao, annual_rate, term_days, status, created_at, due_at,
                    TIMESTAMPDIFF(DAY, created_at, NOW()) AS days_elapsed
             FROM loans
             WHERE user_id = ? AND status = 'ACTIVE'
             ORDER BY created_at DESC"
        );
        $stmt->execute([$user['id']]);
        $rows = $stmt->fetchAll();

        $loans = array_map(function (array $r) {
            $principal = (float) $r['principal_cacao'];
            $rate = (float) $r['annual_rate'];
            $days = (int) $r['days_elapsed'];
            $interest = round($principal * $rate * $days / 365, 2);
            return [
                'id' => $r['id'],
                'principal_cacao' => $principal,
                'annual_rate' => $rate,
                'term_days' => (int) $r['term_days'],
                'days_elapsed' => $days,
                'interest_accrued' => $interest,
                'total_due' => round($principal + $interest, 2),
                'due_at' => $r['due_at'],
                'created_at' => $r['created_at'],
            ];
        }, $rows);

        Response::json(['loans' => $loans]);
    }

    public static function repay(array $user, array $body): never
    {
        $loanId = (string) ($body['loanId'] ?? '');
        if ($loanId === '') {
            throw new AppException(400, 'loanId requerido');
        }

        $pdo = Database::pdo();
        [$pdo, $txnId] = Tx::begin('LOAN_REPAY', Http::idempotencyKey());

        try {
            $stmt = $pdo->prepare(
                "SELECT id, user_id, principal_cacao, annual_rate, status,
                        TIMESTAMPDIFF(DAY, created_at, NOW()) AS days_elapsed
                 FROM loans WHERE id = ? FOR UPDATE"
            );
            $stmt->execute([$loanId]);
            $loan = $stmt->fetch();
            if (!$loan || $loan['user_id'] !== $user['id']) {
                throw new AppException(404, 'Préstamo no encontrado');
            }
            if ($loan['status'] !== 'ACTIVE') {
                throw new AppException(400, 'El préstamo ya fue pagado');
            }

            $principal = (float) $loan['principal_cacao'];
            $rate = (float) $loan['annual_rate'];
            $days = (int) $loan['days_elapsed'];
            $interest = round($principal * $rate * $days / 365, 2);
            $total = round($principal + $interest, 2);
            $totalStr = number_format($total, 2, '.', '');

            $account = Ledger::lockUserCacaoAccount($pdo, $user['id']);
            if ((float) $account['balance'] < $total) {
                throw new AppException(400, 'Saldo de cacao insuficiente para pagar');
            }
            $system = Ledger::systemCacaoAccount($pdo);

            Ledger::addEntry($pdo, $txnId, 'LOAN_REPAY', $account['id'], $system, $totalStr, [
                'loanId' => $loanId, 'principal' => $principal, 'interest' => $interest,
            ]);
            Ledger::updateBalance($pdo, $account['id'], '-' . $totalStr);
            Ledger::updateBalance($pdo, $system, $totalStr);

            $upd = $pdo->prepare("UPDATE loans SET status = 'PAID' WHERE id = ?");
            $upd->execute([$loanId]);

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

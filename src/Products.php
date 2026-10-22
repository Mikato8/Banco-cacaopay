<?php

declare(strict_types=1);

final class Products
{
    public static function list(): never
    {
        $pdo = Database::pdo();
        $stmt = $pdo->query(
            "SELECT p.id, p.name, p.description, p.cacao_price, p.stock, u.name AS seller_name
             FROM products p
             LEFT JOIN users u ON u.id = p.seller_id
             WHERE p.active = TRUE
             ORDER BY p.cacao_price ASC"
        );
        $rows = $stmt->fetchAll();

        $products = array_map(function (array $r) {
            return [
                'id' => $r['id'],
                'name' => $r['name'],
                'description' => $r['description'],
                'cacao_price' => (float) $r['cacao_price'],
                'stock' => (int) $r['stock'],
                'seller' => $r['seller_name'] !== null ? ['name' => $r['seller_name']] : null,
            ];
        }, $rows);

        Response::json(['products' => $products]);
    }

    public static function create(array $user, array $body): never
    {
        Auth::requireRole($user, ['ADMIN', 'SELLER']);

        $name = trim((string) ($body['name'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $price = round((float) ($body['cacaoPrice'] ?? 0), 2);
        $stock = (int) ($body['stock'] ?? 0);

        if ($name === '') {
            throw new AppException(400, 'Nombre requerido');
        }
        if ($price <= 0) {
            throw new AppException(400, 'Precio inválido');
        }
        if ($stock < 0) {
            throw new AppException(400, 'Stock inválido');
        }

        $sellerId = $user['role'] === 'SELLER' ? $user['id'] : null;

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO products (name, description, cacao_price, stock, seller_id)
             VALUES (?, ?, ?::numeric, ?, ?)
             RETURNING id, name, description, cacao_price, stock, active'
        );
        $stmt->execute([$name, $description, number_format($price, 2, '.', ''), $stock, $sellerId]);
        $product = $stmt->fetch();
        $product['cacao_price'] = (float) $product['cacao_price'];
        $product['stock'] = (int) $product['stock'];

        Response::json(['product' => $product], 201);
    }

    public static function redeem(array $user, array $body): never
    {
        $productId = (string) ($body['productId'] ?? '');
        $quantity = (int) ($body['quantity'] ?? 1);

        if ($productId === '') {
            throw new AppException(400, 'productId requerido');
        }
        if ($quantity < 1 || $quantity > 50) {
            throw new AppException(400, 'Cantidad entre 1 y 50');
        }

        $pdo = Database::pdo();
        [$pdo, $txnId] = Tx::begin('REDEEM', Http::idempotencyKey());

        try {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product || !$product['active']) {
                throw new AppException(404, 'Producto no disponible');
            }
            if ((int) $product['stock'] < $quantity) {
                throw new AppException(400, 'Stock insuficiente');
            }

            $total = round((float) $product['cacao_price'] * $quantity, 2);
            $totalStr = number_format($total, 2, '.', '');

            $buyer = Ledger::lockUserCacaoAccount($pdo, $user['id']);
            if ((float) $buyer['balance'] < $total) {
                throw new AppException(400, 'Saldo de cacao insuficiente');
            }

            $sellerAcct = $product['seller_id'] !== null
                ? Ledger::lockUserCacaoAccount($pdo, $product['seller_id'])
                : ['id' => Ledger::systemCacaoAccount($pdo)];

            $meta = ['productId' => $productId, 'quantity' => $quantity];
            Ledger::addEntry($pdo, $txnId, 'REDEEM', $buyer['id'], $sellerAcct['id'], $totalStr, $meta);
            Ledger::updateBalance($pdo, $buyer['id'], '-' . $totalStr);
            Ledger::updateBalance($pdo, $sellerAcct['id'], $totalStr);

            $upd = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
            $upd->execute([$quantity, $productId]);

            $ins = $pdo->prepare(
                'INSERT INTO redemptions (user_id, product_id, cacao_paid, quantity, transaction_id)
                 VALUES (?, ?, ?::numeric, ?, ?)
                 RETURNING id'
            );
            $ins->execute([$user['id'], $productId, $totalStr, $quantity, $txnId]);
            $redemptionId = (string) $ins->fetchColumn();

            $payload = [
                'cacao_balance' => (float) Ledger::balanceOf($pdo, $user['id']),
                'redemption' => [
                    'id' => $redemptionId,
                    'cacao_paid' => $total,
                    'quantity' => $quantity,
                    'product' => ['name' => $product['name'], 'cacao_price' => (float) $product['cacao_price']],
                ],
                'entry' => ['type' => 'REDEEM', 'amount' => $total],
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

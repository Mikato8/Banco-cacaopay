SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            CHAR(36)     NOT NULL PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    name          VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('ADMIN','SELLER','CLIENT') NOT NULL DEFAULT 'CLIENT',
    kyc_status    ENUM('PENDING','VERIFIED','REJECTED') NOT NULL DEFAULT 'PENDING',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts (
    id         CHAR(36) NOT NULL PRIMARY KEY,
    user_id    CHAR(36) NULL,
    kind       ENUM('USER','SYSTEM') NOT NULL,
    currency   ENUM('CACAO','PEN','USD') NOT NULL,
    balance    DECIMAL(20,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounts_user_currency (user_id, currency),
    CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
    id              CHAR(36) NOT NULL PRIMARY KEY,
    idempotency_key CHAR(36) NULL,
    kind            VARCHAR(32) NOT NULL,
    meta            JSON NULL,
    result          JSON NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transactions_idempotency (idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger_entries (
    id               CHAR(36) NOT NULL PRIMARY KEY,
    transaction_id   CHAR(36) NOT NULL,
    entry_type       ENUM('DEPOSIT','TRANSFER_OUT','TRANSFER_IN','REDEEM','ADJUST') NOT NULL,
    debit_account_id  CHAR(36) NOT NULL,
    credit_account_id CHAR(36) NOT NULL,
    amount           DECIMAL(20,2) NOT NULL,
    meta             JSON NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ledger_debit (debit_account_id),
    KEY idx_ledger_credit (credit_account_id),
    KEY idx_ledger_txn (transaction_id),
    CONSTRAINT fk_ledger_txn    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_ledger_debit  FOREIGN KEY (debit_account_id) REFERENCES accounts(id),
    CONSTRAINT fk_ledger_credit FOREIGN KEY (credit_account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id          CHAR(36) NOT NULL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    cacao_price DECIMAL(20,2) NOT NULL,
    stock       INT NOT NULL DEFAULT 0,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    seller_id   CHAR(36) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_seller FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redemptions (
    id             CHAR(36) NOT NULL PRIMARY KEY,
    user_id        CHAR(36) NOT NULL,
    product_id     CHAR(36) NOT NULL,
    cacao_paid     DECIMAL(20,2) NOT NULL,
    quantity       INT NOT NULL,
    transaction_id CHAR(36) NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_redemptions_user    FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_redemptions_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_redemptions_txn     FOREIGN KEY (transaction_id) REFERENCES transactions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO accounts (id, kind, currency, balance)
SELECT UUID(), 'SYSTEM', 'CACAO', 0
WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE kind = 'SYSTEM' AND currency = 'CACAO');
